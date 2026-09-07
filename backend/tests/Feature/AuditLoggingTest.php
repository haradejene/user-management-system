<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\ApplicationStatus;
use App\Enums\MembershipStatus;
use App\Events\IamActivityOccurred;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ApplicationAccessService;
use App\Services\ApplicationService;
use App\Services\AuditService;
use App\Services\CompanyMembershipService;
use App\Services\CompanyService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/login']);
    }

    public function test_authentication_events_capture_context_without_credentials(): void
    {
        $this->withHeader('User-Agent', 'Audit test browser')
            ->postJson('/api/register', [
                'name' => 'Test User', 'email' => 'audit@example.com',
                'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            ])->assertCreated();

        $user = User::query()->sole();
        $log = AuditLog::query()->sole();
        $this->assertSame('auth.registered', $log->action);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($user->public_id, $log->actor_public_id);
        $this->assertSame($user->public_id, $log->subject_id);
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertSame('Audit test browser', $log->user_agent);
        $this->assertTrue($log->actor->is($user));

        $this->postJson('/api/logout')->assertNoContent();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Password123!'])->assertOk();
        $this->assertSame(['auth.registered', 'auth.logout', 'auth.login_failed', 'auth.login'], AuditLog::query()->orderBy('id')->pluck('action')->all());
        $this->assertStringNotContainsString('Password123!', AuditLog::all()->toJson());
        $this->assertStringNotContainsString($user->password, AuditLog::all()->toJson());
    }

    public function test_inactive_login_is_logged_as_failure_without_logout_or_success(): void
    {
        $user = User::factory()->create(['status' => AccountStatus::Suspended]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
        $log = AuditLog::query()->sole();
        $this->assertSame('auth.login_failed', $log->action);
        $this->assertNull($log->actor_id);
        $this->assertNull($log->actor_public_id);
        $this->assertSame('inactive_account', $log->metadata['reason']);
        $this->assertGuest();
    }

    public function test_management_changes_record_actor_and_skip_no_ops(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $this->actingAs($admin);
        $users = app(UserService::class);
        $user = $users->create(['name' => 'Before', 'email' => 'change@example.com', 'password' => 'secret-password']);
        $users->update($user, ['name' => 'After', 'password' => 'new-secret-password']);
        $users->changeStatus($user, AccountStatus::Suspended, $admin);
        $users->changeStatus($user, AccountStatus::Suspended, $admin);

        $this->assertSame(['user.created', 'user.updated', 'user.status_changed'], AuditLog::query()->orderBy('id')->pluck('action')->all());
        $this->assertSame(['name', 'password'], AuditLog::query()->where('action', 'user.updated')->sole()->metadata['changed_fields']);
        $log = AuditLog::query()->latest('id')->first();
        $this->assertSame(['previous_status' => 'active', 'status' => 'suspended'], $log->metadata);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertStringNotContainsString('secret-password', AuditLog::all()->toJson());
    }

    public function test_company_application_and_relationship_events_are_recorded(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $this->actingAs($admin);
        $user = User::factory()->create();
        $company = app(CompanyService::class)->create(['name' => 'Company']);
        app(CompanyService::class)->update($company, ['name' => 'Renamed']);
        $application = app(ApplicationService::class)->create(['name' => 'App', 'slug' => 'app']);
        app(ApplicationService::class)->update($application, ['name' => 'Renamed']);
        app(CompanyMembershipService::class)->add($company, $user);
        app(CompanyMembershipService::class)->remove($company, $user);
        app(ApplicationAccessService::class)->grant($user, $application, $admin);
        app(ApplicationAccessService::class)->revoke($user, $application);
        app(ApplicationAccessService::class)->revoke($user, $application);
        app(CompanyService::class)->changeStatus($company, MembershipStatus::Inactive);
        app(ApplicationService::class)->changeStatus($application, ApplicationStatus::Inactive);

        $this->assertSame([
            'company.created', 'company.updated', 'application.created', 'application.updated',
            'company.member_added', 'company.member_removed', 'application.access_granted', 'application.access_revoked',
            'company.status_changed', 'application.status_changed',
        ], AuditLog::query()->orderBy('id')->pluck('action')->all());
        $this->assertSame($application->public_id, AuditLog::query()->where('action', 'application.access_revoked')->sole()->metadata['application_id']);
        $this->assertSame([$admin->id], AuditLog::query()->distinct()->pluck('actor_id')->all());
    }

    public function test_rejected_requests_do_not_create_success_records(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/admin/companies', ['name' => 'Forbidden'])->assertForbidden();
        $this->actingAs(User::factory()->systemAdmin()->create())->postJson('/api/admin/companies', [])->assertUnprocessable();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_business_change(): void
    {
        $this->mock(AuditService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        });
        try {
            app(CompanyService::class)->create(['name' => 'Must roll back']);
            $this->fail('Expected an audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_metadata_is_allowlisted_and_history_survives_actor_deletion(): void
    {
        $actor = User::factory()->create();
        $log = app(AuditService::class)->record('user.updated', $actor, [
            'password' => 'secret', 'token' => 'secret-token', 'status' => 'active',
        ], $actor);
        $actor->forceDelete();
        $this->assertNull($log->refresh()->actor_id);
        $this->assertSame($actor->public_id, $log->actor_public_id);
        $this->assertSame($actor->public_id, $log->subject_id);
        $this->assertSame(['status' => 'active'], $log->metadata);
    }

    public function test_listener_record_rolls_back_with_an_outer_transaction(): void
    {
        try {
            DB::transaction(function (): void {
                app(CompanyService::class)->create(['name' => 'Rolled back']);
                $this->assertDatabaseCount('audit_logs', 1);
                throw new RuntimeException('Later operation failed');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Later operation failed', $exception->getMessage());
        }

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_access_service_emits_activity_with_explicit_actor_and_target(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();
        $application = Application::factory()->create();
        Event::fake([IamActivityOccurred::class]);

        app(ApplicationAccessService::class)->grant($user, $application, $admin);

        Event::assertDispatched(IamActivityOccurred::class, fn (IamActivityOccurred $event): bool => $event->action === 'application.access_granted'
            && $event->actor->is($admin)
            && $event->subject->is($user)
            && $event->metadata === ['application_id' => $application->public_id]
        );
        Event::assertDispatchedTimes(IamActivityOccurred::class, 1);
        $this->assertDatabaseHas('application_user', ['user_id' => $user->id, 'application_id' => $application->id]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_reactivation_endpoint_records_transition_and_administrator(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $user = User::factory()->create(['status' => AccountStatus::Suspended]);

        $this->actingAs($admin)->patchJson("/api/admin/users/{$user->public_id}/reactivate")->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('user.status_changed', $log->action);
        $this->assertSame($admin->public_id, $log->actor_public_id);
        $this->assertSame('user', $log->subject_type);
        $this->assertSame($user->public_id, $log->subject_id);
        $this->assertSame(['previous_status' => 'suspended', 'status' => 'active'], $log->metadata);
    }

    public function test_application_activation_records_one_event_and_skips_repeat(): void
    {
        $application = Application::factory()->create(['status' => ApplicationStatus::Inactive]);
        $this->actingAs(User::factory()->systemAdmin()->create());
        $url = "/api/admin/applications/{$application->public_id}/activate";
        $this->patchJson($url)->assertOk();
        $this->patchJson($url)->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('application.status_changed', $log->action);
        $this->assertSame($application->public_id, $log->subject_id);
        $this->assertSame(['previous_status' => 'inactive', 'status' => 'active'], $log->metadata);
    }

    public function test_duplicate_access_grant_does_not_emit_another_success(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->create();
        $this->actingAs(User::factory()->systemAdmin()->create());
        $url = "/api/admin/users/{$user->public_id}/applications";
        $this->postJson($url, ['application_id' => $application->public_id])->assertCreated();
        $this->postJson($url, ['application_id' => $application->public_id])->assertUnprocessable();

        $this->assertSame('application.access_granted', AuditLog::query()->sole()->action);
    }

    public function test_unknown_login_records_failure_without_claiming_an_identity(): void
    {
        $this->postJson('/api/login', ['email' => 'unknown@example.com', 'password' => 'secret'])->assertUnprocessable();

        $log = AuditLog::query()->sole();
        $this->assertSame('auth.login_failed', $log->action);
        $this->assertNull($log->actor_id);
        $this->assertNull($log->actor_public_id);
        $this->assertNull($log->subject_type);
        $this->assertNull($log->subject_id);
        $this->assertSame(['reason' => 'invalid_credentials'], $log->metadata);
    }

    public function test_listener_preserves_explicit_actor_and_caps_user_agent_with_server_timestamp(): void
    {
        $this->travelTo(now()->startOfSecond());
        $actor = User::factory()->systemAdmin()->create();
        $other = User::factory()->create();
        $this->actingAs($other);
        app('request')->headers->set('User-Agent', str_repeat('x', 1500));

        IamActivityOccurred::dispatch('user.updated', $other, ['changed_fields' => ['name']], $actor);

        $log = AuditLog::query()->sole();
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame($actor->public_id, $log->actor_public_id);
        $this->assertSame(str_repeat('x', 1024), $log->user_agent);
        $this->assertTrue($log->created_at->equalTo(now()));
    }
}
