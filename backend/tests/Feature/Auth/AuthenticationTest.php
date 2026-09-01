<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const SPA_HEADERS = [
        'Accept' => 'application/json',
        'Origin' => 'http://localhost:3000',
        'Referer' => 'http://localhost:3000/login',
    ];

    public function test_successful_registration_creates_and_authenticates_an_active_user(): void
    {
        $response = $this->withHeaders(self::SPA_HEADERS)->postJson('/api/register', [
            'name' => 'New User',
            'email' => ' New.User@Example.test ',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.email', 'new.user@example.test')
            ->assertJsonPath('data.status', AccountStatus::Active->value)
            ->assertJsonPath('data.is_system_admin', false)
            ->assertJsonMissingPath('data.password');

        $user = User::query()->where('email', 'new.user@example.test')->firstOrFail();

        $this->assertTrue(Hash::check('secure-password', $user->password));
        $this->assertNotSame('secure-password', $user->password);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);

        $this->withHeaders(self::SPA_HEADERS)->postJson('/api/register', [
            'name' => 'Duplicate User',
            'email' => 'EXISTING@example.test',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_invalid_input(): void
    {
        $this->withHeaders(self::SPA_HEADERS)->postJson('/api/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_successful_login_authenticates_the_user(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.test',
            'password' => 'secure-password',
        ]);

        $this->withHeaders(self::SPA_HEADERS)->postJson('/api/login', [
            'email' => 'USER@example.test',
            'password' => 'secure-password',
        ])->assertOk()->assertJsonPath('data.id', $user->public_id);

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        User::factory()->create([
            'email' => 'user@example.test',
            'password' => 'correct-password',
        ]);

        $this->withHeaders(self::SPA_HEADERS)->postJson('/api/login', [
            'email' => 'user@example.test',
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertGuest('web');
    }

    public function test_an_inactive_user_cannot_authenticate(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => 'secure-password',
            'status' => AccountStatus::Inactive,
        ]);

        $this->withHeaders(self::SPA_HEADERS)->postJson('/api/login', [
            'email' => 'inactive@example.test',
            'password' => 'secure-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertGuest('web');
    }

    public function test_an_authenticated_active_user_can_request_their_identity(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeaders(self::SPA_HEADERS)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->public_id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password');
    }

    public function test_an_unauthenticated_user_cannot_request_identity(): void
    {
        $this->withHeaders(self::SPA_HEADERS)
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_an_authenticated_inactive_user_is_rejected_by_account_status_middleware(): void
    {
        $user = User::factory()->create(['status' => AccountStatus::Inactive]);

        $this->actingAs($user, 'web')
            ->withHeaders(self::SPA_HEADERS)
            ->getJson('/api/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is not active.');

        $this->assertGuest('web');
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = User::factory()->create(['password' => 'secure-password']);

        $this->withHeaders(self::SPA_HEADERS)->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secure-password',
        ])->assertOk();

        $this->withHeaders(self::SPA_HEADERS)->postJson('/api/logout')->assertNoContent();

        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited_after_five_attempts_per_identity_and_ip(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withHeaders(self::SPA_HEADERS)->postJson('/api/login', [
                'email' => 'throttled@example.test',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->withHeaders(self::SPA_HEADERS)->postJson('/api/login', [
            'email' => 'throttled@example.test',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }
}
