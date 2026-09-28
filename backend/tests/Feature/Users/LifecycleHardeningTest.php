<?php

namespace Tests\Feature\Users;

use App\Enums\AccountStatus;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ApplicationAccessService;
use App\Services\AuditService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LifecycleHardeningTest extends TestCase
{
    use RefreshDatabase;

    public static function transitions(): array
    {
        return [
            ['active', 'inactive', 'deactivate', 200],
            ['active', 'suspended', 'suspend', 200],
            ['inactive', 'active', 'reactivate', 200],
            ['inactive', 'suspended', 'suspend', 200],
            ['suspended', 'active', 'reactivate', 200],
            ['suspended', 'inactive', 'deactivate', 422],
            ['active', 'active', 'reactivate', 200],
            ['inactive', 'inactive', 'deactivate', 200],
            ['suspended', 'suspended', 'suspend', 200],
        ];
    }

    #[DataProvider('transitions')]
    public function test_transitions_and_audits(string $from, string $to, string $action, int $response): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $user = User::factory()->create(['status' => $from]);
        $this->actingAs($admin)->patchJson("/api/admin/users/{$user->public_id}/{$action}")->assertStatus($response);
        $this->assertSame($response === 200 ? $to : $from, $user->fresh()->status->value);
        if ($response !== 200 || $from === $to) {
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertSame(0, $user->fresh()->session_version);

            return;
        }
        $log = AuditLog::query()->sole();
        $this->assertSame('user.status_changed', $log->action);
        $this->assertSame($admin->public_id, $log->actor_public_id);
        $this->assertSame($user->public_id, $log->subject_id);
        $this->assertSame($from, $log->metadata['previous_status']);
        $this->assertSame($to, $log->metadata['status']);
        $this->assertSame($from === 'suspended' ? 'suspension_released' : null, $log->metadata['reason'] ?? null);
    }

    public static function actors(): array
    {
        $cases = [];
        foreach (['guest', 'ordinary', 'inactive', 'suspended', 'deleted'] as $actor) {
            foreach (['deactivate', 'suspend', 'reactivate'] as $action) {
                $cases["{$actor}-{$action}"] = [$actor, $action];
            }
        }

        return $cases;
    }

    #[DataProvider('actors')]
    public function test_ineligible_actors_cannot_change_status(string $kind, string $action): void
    {
        $target = User::factory()->create();
        if ($kind !== 'guest') {
            $actor = User::factory()->systemAdmin()->create();
            if ($kind === 'ordinary') {
                $actor->forceFill(['is_system_admin' => false])->save();
            } elseif ($kind === 'deleted') {
                $actor->delete();
            } else {
                $actor->update(['status' => $kind]);
            }
            $this->actingAs($actor);
            $this->assertFalse(Gate::forUser($actor)->allows('changeStatus', $target));
        }
        $this->patchJson("/api/admin/users/{$target->public_id}/{$action}")->assertStatus($kind === 'guest' ? 401 : 403);
        $this->assertSame(AccountStatus::Active, $target->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function selfActions(): array
    {
        return [['deactivate', false], ['suspend', false], ['deactivate', true], ['suspend', true]];
    }

    #[DataProvider('selfActions')]
    public function test_self_disable_is_rejected_with_one_or_several_admins(string $action, bool $other): void
    {
        $admin = User::factory()->systemAdmin()->create();
        if ($other) {
            User::factory()->systemAdmin()->create();
        }
        $this->actingAs($admin)->patchJson("/api/admin/users/{$admin->public_id}/{$action}")->assertUnprocessable();
        $this->assertTrue($admin->isCentralIamAdministrator());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_disabled_stale_actor_cannot_disable_the_remaining_admin(): void
    {
        $first = User::factory()->systemAdmin()->create();
        $second = User::factory()->systemAdmin()->create();
        app(UserService::class)->changeStatus($second, AccountStatus::Inactive, $first);
        try {
            app(UserService::class)->changeStatus($first, AccountStatus::Inactive, $second);
            $this->fail('Stale actor was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertTrue($first->isCentralIamAdministrator());
        $this->assertFalse($second->isCentralIamAdministrator());
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public static function eligibility(): array
    {
        $cases = [];
        foreach (['active', 'inactive', 'suspended', 'deleted'] as $user) {
            foreach (['active', 'inactive', 'deleted'] as $app) {
                foreach (['active', 'inactive', 'absent'] as $assignment) {
                    $cases["{$user}-{$app}-{$assignment}"] = [$user, $app, $assignment];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('eligibility')]
    public function test_effective_access_uses_current_persisted_state(string $userState, string $appState, string $assignment): void
    {
        $user = User::factory()->create();
        $app = Application::factory()->create();
        if ($assignment !== 'absent') {
            $user->applications()->attach($app, ['status' => $assignment]);
        }
        // Mutate different instances to exercise stale in-memory models too.
        if ($userState === 'deleted') {
            $user->fresh()->delete();
        } else {
            $user->fresh()->update(['status' => $userState]);
        }
        if ($appState === 'deleted') {
            $app->fresh()->delete();
        } else {
            $app->fresh()->update(['status' => $appState]);
        }
        $expected = $userState === 'active' && $appState === 'active' && $assignment === 'active';
        $this->assertSame($expected, app(ApplicationAccessService::class)->isAllowed($user, $app));
        $this->assertSame($expected, User::withTrashed()->findOrFail($user->id)->hasAccessToApplication(Application::withTrashed()->findOrFail($app->id)));
        $this->assertDatabaseCount('application_user', $assignment === 'absent' ? 0 : 1);
    }

    public function test_deleted_identity_is_denied_by_login_predicates_and_direct_lifecycle_calls(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $deleted = User::factory()->systemAdmin()->create();
        $deleted->fresh()->delete();
        $this->assertFalse($deleted->isActive());
        $this->assertFalse($deleted->isCentralIamAdministrator());
        $archived = User::withTrashed()->findOrFail($deleted->id);
        $this->assertFalse($archived->isActive());
        $this->assertFalse($archived->isCentralIamAdministrator());
        $this->withHeaders(['Origin' => 'http://localhost:3000'])->postJson('/api/login', ['email' => $deleted->email, 'password' => 'password'])->assertUnprocessable();
        $this->assertGuest();
        foreach ([[$archived, $admin, 404], [$admin, $archived, 403]] as [$target, $actor, $status]) {
            try {
                app(UserService::class)->changeStatus($target, AccountStatus::Active, $actor);
                $this->fail('Deleted model was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame($status, $exception->getStatusCode());
            }
        }
        $this->assertSame(0, AuditLog::query()->where('action', 'user.status_changed')->count());
    }

    public function test_email_verification_and_protected_fields(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $user = User::factory()->create(['email' => 'verified@example.test']);
        $verifiedAt = $user->email_verified_at;
        $this->actingAs($admin)->patchJson("/api/admin/users/{$user->public_id}", ['email' => ' VERIFIED@Example.test '])->assertOk();
        $this->assertTrue($verifiedAt->equalTo($user->fresh()->email_verified_at));
        app(UserService::class)->update($user, ['email' => ' Changed@Example.test ', 'status' => 'suspended', 'deleted_at' => now(), 'is_system_admin' => true, 'public_id' => 'replacement']);
        $fresh = $user->fresh();
        $this->assertNull($fresh->email_verified_at);
        $this->assertSame('changed@example.test', $fresh->email);
        $this->assertSame(AccountStatus::Active, $fresh->status);
        $this->assertFalse($fresh->is_system_admin);
        $this->assertSame($user->public_id, $fresh->public_id);
        $this->assertNull($fresh->deleted_at);
    }

    public function test_ordinary_update_endpoint_ignores_lifecycle_and_identity_fields(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();
        $this->actingAs($admin)->patchJson("/api/admin/users/{$user->public_id}", [
            'name' => 'Updated name',
            'status' => 'suspended',
            'deleted_at' => now()->toISOString(),
            'public_id' => $admin->public_id,
            'is_system_admin' => true,
            'session_version' => 99,
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('Updated name', $fresh->name);
        $this->assertSame(AccountStatus::Active, $fresh->status);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame($user->public_id, $fresh->public_id);
        $this->assertFalse($fresh->is_system_admin);
        $this->assertSame(0, $fresh->session_version);
        $this->assertSame(['name'], AuditLog::query()->sole()->metadata['changed_fields']);
    }

    public function test_stale_target_cannot_bypass_suspension_and_no_op_uses_current_state(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();
        app(UserService::class)->changeStatus($user, AccountStatus::Suspended, $admin);
        app(UserService::class)->changeStatus($user, AccountStatus::Suspended, $admin);
        try {
            app(UserService::class)->changeStatus($user, AccountStatus::Inactive, $admin);
            $this->fail('Suspension bypassed with stale target.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->assertSame(AccountStatus::Suspended, $user->fresh()->status);
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_reactivated_actor_snapshot_cannot_reuse_prior_authorization(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $other = User::factory()->systemAdmin()->create();
        app(UserService::class)->changeStatus($other, AccountStatus::Inactive, $admin);
        app(UserService::class)->changeStatus($other, AccountStatus::Active, $admin);
        try {
            app(UserService::class)->changeStatus($admin, AccountStatus::Inactive, $other);
            $this->fail('Old actor version was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertTrue($admin->isActive());
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_grant_rejects_stale_deleted_entities_and_inactive_applications(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();
        foreach (['inactive', 'deleted'] as $state) {
            $app = Application::factory()->create();
            if ($state === 'deleted') {
                $app->fresh()->delete();
            } else {
                $app->fresh()->update(['status' => $state]);
            }
            try {
                app(ApplicationAccessService::class)->grant($user, $app, $admin);
                $this->fail('Ineligible application received a grant.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('application_id', $exception->errors());
            }
        }
        $user->fresh()->delete();
        try {
            app(ApplicationAccessService::class)->grant($user, Application::factory()->create(), $admin);
            $this->fail('Deleted user received a grant.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('application_user', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_status_session_version_and_remember_token(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();
        $token = $user->remember_token;
        $this->mock(AuditService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        });
        try {
            app(UserService::class)->changeStatus($user, AccountStatus::Suspended, $admin);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $user->refresh();
        $this->assertSame(AccountStatus::Active, $user->status);
        $this->assertSame(0, $user->session_version);
        $this->assertSame($token, $user->remember_token);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_lifecycle_preserves_related_records_and_inactive_assignments(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();
        $profile = UserProfile::factory()->create(['user_id' => $user->id]);
        $company = Company::factory()->create();
        $user->companies()->attach($company, ['status' => 'inactive']);
        $active = Application::factory()->create();
        $inactive = Application::factory()->create();
        $user->applications()->attach($active, ['status' => 'active']);
        $user->applications()->attach($inactive, ['status' => 'inactive']);
        app(UserService::class)->changeStatus($user, AccountStatus::Suspended, $admin);
        $this->assertFalse($user->hasAccessToApplication($active));
        app(UserService::class)->changeStatus($user, AccountStatus::Active, $admin);
        $this->assertTrue($user->hasAccessToApplication($active));
        $this->assertFalse($user->hasAccessToApplication($inactive));
        $this->assertEquals($profile->getAttributes(), $profile->fresh()->getAttributes());
        $this->assertDatabaseHas('company_user', ['user_id' => $user->id, 'company_id' => $company->id, 'status' => 'inactive']);
    }
}
