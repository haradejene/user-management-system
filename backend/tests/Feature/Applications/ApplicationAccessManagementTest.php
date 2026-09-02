<?php

namespace Tests\Feature\Applications;

use App\Enums\AccountStatus;
use App\Enums\ApplicationStatus;
use App\Enums\MembershipStatus;
use App\Models\Application;
use App\Models\User;
use App\Services\ApplicationAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApplicationAccessManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_grant_application_access(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $user = User::factory()->create(['name' => 'Hara']);
        $application = Application::factory()->create(['name' => 'HRM', 'slug' => 'hrm']);

        $this->actingAs($administrator)
            ->postJson("/api/admin/users/{$user->public_id}/applications", [
                'application_id' => $application->public_id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', $application->public_id)
            ->assertJsonPath('data.slug', 'hrm')
            ->assertJsonPath('data.access_status', MembershipStatus::Active->value);

        $this->assertDatabaseHas('application_user', [
            'user_id' => $user->id,
            'application_id' => $application->id,
            'status' => MembershipStatus::Active->value,
            'granted_by' => $administrator->id,
        ]);
        $this->assertTrue($user->hasAccessToApplication($application));
    }

    public function test_duplicate_application_access_is_rejected(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();
        $application = Application::factory()->create();
        $user->applications()->attach($application, [
            'status' => MembershipStatus::Active->value,
            'granted_by' => $administrator->id,
        ]);

        $this->actingAs($administrator)
            ->postJson("/api/admin/users/{$user->public_id}/applications", [
                'application_id' => $application->public_id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('application_id');

        $this->assertDatabaseCount('application_user', 1);
    }

    public function test_administrator_can_revoke_application_access(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();
        $application = Application::factory()->create();
        $user->applications()->attach($application, [
            'status' => MembershipStatus::Active->value,
            'granted_by' => $administrator->id,
        ]);

        $this->actingAs($administrator)
            ->deleteJson("/api/admin/users/{$user->public_id}/applications/{$application->public_id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('application_user', [
            'user_id' => $user->id,
            'application_id' => $application->id,
        ]);
        $this->assertFalse($user->hasAccessToApplication($application));
    }

    public function test_administrator_can_list_applications_for_a_user_and_users_for_an_application(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $hara = User::factory()->create(['name' => 'Hara']);
        $hrm = Application::factory()->create(['name' => 'HRM', 'slug' => 'hrm']);
        $erp = Application::factory()->create(['name' => 'ERP', 'slug' => 'erp']);
        $hara->applications()->attach([$hrm->id, $erp->id], [
            'status' => MembershipStatus::Active->value,
            'granted_by' => $administrator->id,
        ]);

        $this->actingAs($administrator)
            ->getJson("/api/admin/users/{$hara->public_id}/applications")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['slug' => 'hrm'])
            ->assertJsonFragment(['slug' => 'erp']);

        $this->actingAs($administrator)
            ->getJson("/api/admin/applications/{$hrm->public_id}/users")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $hara->public_id)
            ->assertJsonPath('data.0.access_status', MembershipStatus::Active->value)
            ->assertJsonMissingPath('data.0.password');
    }

    public function test_standard_and_unauthenticated_users_cannot_grant_or_revoke_access(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->create();
        $user->applications()->attach($application, ['status' => MembershipStatus::Active->value]);

        $this->postJson("/api/admin/users/{$user->public_id}/applications", [
            'application_id' => $application->public_id,
        ])->assertUnauthorized();

        $this->actingAs($user)
            ->postJson("/api/admin/users/{$user->public_id}/applications", [
                'application_id' => $application->public_id,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->deleteJson("/api/admin/users/{$user->public_id}/applications/{$application->public_id}")
            ->assertForbidden();
    }

    public function test_access_cannot_be_granted_to_an_inactive_application(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();
        $application = Application::factory()->create(['status' => ApplicationStatus::Inactive]);

        $this->actingAs($administrator)
            ->postJson("/api/admin/users/{$user->public_id}/applications", [
                'application_id' => $application->public_id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('application_id');

        $this->assertFalse($user->hasAccessToApplication($application));
        $this->assertDatabaseCount('application_user', 0);
    }

    public function test_deactivating_application_or_user_makes_an_existing_grant_ineffective(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();
        $application = Application::factory()->create();
        $user->applications()->attach($application, [
            'status' => MembershipStatus::Active->value,
            'granted_by' => $administrator->id,
        ]);
        $access = app(ApplicationAccessService::class);

        $this->assertTrue($access->isAllowed($user, $application));

        $application->update(['status' => ApplicationStatus::Inactive]);
        $this->assertFalse($access->isAllowed($user, $application->fresh()));
        $this->assertDatabaseHas('application_user', [
            'user_id' => $user->id,
            'application_id' => $application->id,
        ]);

        $application->update(['status' => ApplicationStatus::Active]);
        $user->update(['status' => AccountStatus::Suspended]);
        $this->assertFalse($access->isAllowed($user->fresh(), $application->fresh()));
    }

    public function test_access_input_is_validated_and_no_role_fields_exist(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();

        $this->actingAs($administrator)
            ->postJson("/api/admin/users/{$user->public_id}/applications", [
                'application_id' => 'not-a-uuid',
                'role' => 'HR_MANAGER',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('application_id');

        $this->assertFalse(Schema::hasColumn('application_user', 'role'));
        $this->assertFalse(Schema::hasColumn('application_user', 'permissions'));
    }
}
