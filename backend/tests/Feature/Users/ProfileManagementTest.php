<?php

namespace Tests\Feature\Users;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_and_update_own_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user_id', $user->public_id)
            ->assertJsonPath('data.first_name', null);

        $this->actingAs($user)->patchJson('/api/profile', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'phone' => '+251900000000',
            'photo' => 'https://example.test/avatar.png',
        ])->assertOk()
            ->assertJsonPath('data.first_name', 'Ada')
            ->assertJsonPath('data.photo', 'https://example.test/avatar.png');

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'phone' => '+251900000000',
            'profile_photo' => 'https://example.test/avatar.png',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.profile_updated', 'subject_id' => $user->public_id]);
    }

    public function test_nullable_profile_fields_can_be_cleared_and_omitted_fields_are_preserved(): void
    {
        $user = User::factory()->create();
        $user->profile()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'phone' => '123']);

        $this->actingAs($user)->patchJson('/api/profile', ['first_name' => null])->assertOk();

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'first_name' => null,
            'last_name' => 'Lovelace',
            'phone' => '123',
        ]);
    }

    public function test_profile_limits_are_validated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/profile', [
            'first_name' => str_repeat('a', 101),
            'last_name' => str_repeat('a', 101),
            'phone' => str_repeat('1', 31),
            'photo' => str_repeat('p', 2049),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'phone', 'photo']);
    }

    public function test_standard_user_cannot_modify_another_users_profile(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($actor)->patchJson("/api/admin/users/{$target->public_id}/profile", ['first_name' => 'Nope'])
            ->assertForbidden();
        $this->assertDatabaseMissing('user_profiles', ['user_id' => $target->id]);
    }

    public function test_central_admin_can_view_and_update_another_users_profile(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)->getJson("/api/admin/users/{$target->public_id}/profile")
            ->assertOk()->assertJsonPath('data.user_id', $target->public_id);
        $this->actingAs($admin)->patchJson("/api/admin/users/{$target->public_id}/profile", ['last_name' => 'Admin edited'])
            ->assertOk()->assertJsonPath('data.last_name', 'Admin edited');
    }

    public function test_profile_update_does_not_change_identity_lifecycle_or_access_fields(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();
        $application = Application::factory()->create();
        $user->applications()->attach($application, ['status' => 'active', 'granted_by' => $admin->id]);
        $before = $user->fresh()->only(['public_id', 'email', 'status', 'is_system_admin']);

        $this->actingAs($user)->patchJson('/api/profile', [
            'first_name' => 'Profile only',
            'status' => 'suspended',
            'is_system_admin' => true,
            'email' => 'changed@example.test',
        ])->assertOk();

        $after = $user->fresh()->only(['public_id', 'email', 'status', 'is_system_admin']);
        $this->assertSame($before, $after);
        $this->assertDatabaseHas('application_user', ['user_id' => $user->id, 'application_id' => $application->id]);
    }

    public function test_noop_profile_update_does_not_create_success_audit(): void
    {
        $user = User::factory()->create();
        $user->profile()->create(['first_name' => 'Ada']);

        $this->actingAs($user)->patchJson('/api/profile', ['first_name' => 'Ada'])->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'user.profile_updated')->count());
    }

    public function test_audit_failure_rolls_back_profile_mutation(): void
    {
        $user = User::factory()->create();
        $this->mock(AuditService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        });

        $this->actingAs($user)->patchJson('/api/profile', ['first_name' => 'Must roll back'])
            ->assertStatus(500);

        $this->assertDatabaseMissing('user_profiles', ['user_id' => $user->id]);
    }
}
