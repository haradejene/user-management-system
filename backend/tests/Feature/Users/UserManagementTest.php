<?php

namespace Tests\Feature\Users;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const SPA_HEADERS = [
        'Accept' => 'application/json',
        'Origin' => 'http://localhost:3000',
        'Referer' => 'http://localhost:3000/login',
    ];

    public function test_administrator_can_list_and_filter_users(): void
    {
        $administrator = User::factory()->systemAdmin()->create(['name' => 'Administrator']);
        User::factory()->create(['name' => 'Active Match', 'email' => 'match@example.test']);
        User::factory()->create([
            'name' => 'Suspended Match',
            'email' => 'suspended@example.test',
            'status' => AccountStatus::Suspended,
        ]);

        $this->actingAs($administrator)
            ->getJson('/api/admin/users?search=match&status=active&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'match@example.test')
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_administrator_can_view_a_user_by_public_id_without_sensitive_fields(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();

        $this->actingAs($administrator)
            ->getJson("/api/admin/users/{$user->public_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $user->public_id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $this->actingAs($administrator)
            ->getJson("/api/admin/users/{$user->id}")
            ->assertNotFound();
    }

    public function test_administrator_can_create_a_user_with_a_hashed_password(): void
    {
        $administrator = User::factory()->systemAdmin()->create();

        $this->actingAs($administrator)
            ->postJson('/api/admin/users', [
                'name' => 'Managed User',
                'email' => ' Managed.User@Example.test ',
                'password' => 'secure-password',
                'password_confirmation' => 'secure-password',
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'managed.user@example.test')
            ->assertJsonPath('data.status', AccountStatus::Active->value)
            ->assertJsonPath('data.is_system_admin', false)
            ->assertJsonMissingPath('data.password');

        $user = User::query()->where('email', 'managed.user@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('secure-password', $user->password));
        $this->assertNotSame('secure-password', $user->password);
    }

    public function test_administrator_can_update_a_user(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $user = User::factory()->create(['password' => 'old-password']);

        $this->actingAs($administrator)
            ->patchJson("/api/admin/users/{$user->public_id}", [
                'name' => 'Updated User',
                'email' => 'UPDATED@example.test',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated User')
            ->assertJsonPath('data.email', 'updated@example.test')
            ->assertJsonMissingPath('data.password');

        $user->refresh();
        $this->assertTrue(Hash::check('new-secure-password', $user->password));
    }

    public function test_create_and_update_requests_are_validated(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $user = User::factory()->create(['email' => 'existing@example.test']);

        $this->actingAs($administrator)
            ->postJson('/api/admin/users', [
                'name' => '',
                'email' => 'not-an-email',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        $otherUser = User::factory()->create();

        $this->actingAs($administrator)
            ->patchJson("/api/admin/users/{$otherUser->public_id}", [
                'email' => $user->email,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_administrator_can_deactivate_suspend_and_reactivate_users(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();

        $this->actingAs($administrator)
            ->patchJson("/api/admin/users/{$user->public_id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.status', AccountStatus::Inactive->value);

        $this->actingAs($administrator)
            ->patchJson("/api/admin/users/{$user->public_id}/suspend")
            ->assertOk()
            ->assertJsonPath('data.status', AccountStatus::Suspended->value);

        $this->actingAs($administrator)
            ->patchJson("/api/admin/users/{$user->public_id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.status', AccountStatus::Active->value);
    }

    public function test_suspended_user_cannot_authenticate_but_can_after_reactivation(): void
    {
        $administrator = User::factory()->systemAdmin()->create();
        $user = User::factory()->create([
            'email' => 'lifecycle@example.test',
            'password' => 'secure-password',
        ]);

        $this->actingAs($administrator)
            ->patchJson("/api/admin/users/{$user->public_id}/suspend")
            ->assertOk();

        $this->withHeaders(self::SPA_HEADERS)->postJson('/api/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->withHeaders(self::SPA_HEADERS)->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secure-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();

        $this->actingAs($administrator)
            ->patchJson("/api/admin/users/{$user->public_id}/reactivate")
            ->assertOk();

        $this->withHeaders(self::SPA_HEADERS)->postJson('/api/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->withHeaders(self::SPA_HEADERS)->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secure-password',
        ])->assertOk();
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_administrator_cannot_disable_their_own_account(): void
    {
        $administrator = User::factory()->systemAdmin()->create();

        $this->actingAs($administrator)
            ->patchJson("/api/admin/users/{$administrator->public_id}/suspend")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(AccountStatus::Active, $administrator->fresh()->status);
    }

    public function test_standard_and_unauthenticated_users_cannot_access_admin_operations(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/admin/users')->assertUnauthorized();

        $this->actingAs($user)
            ->getJson('/api/admin/users')
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/admin/users', [
                'name' => 'Forbidden User',
                'email' => 'forbidden@example.test',
                'password' => 'secure-password',
                'password_confirmation' => 'secure-password',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->patchJson("/api/admin/users/{$user->public_id}/suspend")
            ->assertForbidden();
    }
}
