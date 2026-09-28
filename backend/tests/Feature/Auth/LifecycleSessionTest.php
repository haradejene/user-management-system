<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LifecycleSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/login']);
    }

    public static function disablingStates(): array
    {
        return [[AccountStatus::Inactive], [AccountStatus::Suspended]];
    }

    private function loginAndCapture(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password', 'remember' => true])->assertOk();
        $this->assertSame($user->fresh()->session_version, session('auth_session_version'));

        return session()->all();
    }

    private function replay(array $payload): void
    {
        $this->app['auth']->forgetGuards();
        $this->withSession([]);
        session()->flush();
        session()->put($payload);
        session()->save();
    }

    #[DataProvider('disablingStates')]
    public function test_all_prior_sessions_and_remember_credentials_stay_invalid_after_reactivation(AccountStatus $status): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();
        $first = $this->loginAndCapture($user);
        $second = $this->loginAndCapture($user);
        $oldToken = $user->fresh()->remember_token;

        app(UserService::class)->changeStatus($user, $status, $admin);
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertNotSame($oldToken, $user->fresh()->remember_token);
        $this->replay($first);
        $this->getJson('/api/me')->assertForbidden();
        $this->assertGuest('web');

        app(UserService::class)->changeStatus($user, AccountStatus::Active, $admin);
        foreach ([$first, $second] as $payload) {
            $this->replay($payload);
            $this->getJson('/api/me')->assertUnauthorized();
            $this->assertGuest('web');
        }
        // The framework's remember-cookie provider cannot resolve the old credential.
        $this->assertNull(Auth::guard('web')->getProvider()->retrieveByToken($user->id, $oldToken));
        $this->loginAndCapture($user);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me')->assertOk();
    }

    public function test_sessions_without_a_version_require_fresh_login(): void
    {
        $user = User::factory()->create();
        $payload = $this->loginAndCapture($user);
        unset($payload['auth_session_version']);
        $this->replay($payload);
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_a_loaded_identity_without_a_session_cannot_bypass_version_validation(): void
    {
        $user = User::factory()->create();
        $request = Request::create('/api/me');
        $request->setUserResolver(fn () => $user);
        $response = app(EnsureAccountIsActive::class)->handle($request, function () {
            $this->fail('Missing session was accepted.');
        });
        $this->assertSame(401, $response->getStatusCode());
    }

    #[DataProvider('disablingStates')]
    public function test_real_remember_cookie_cannot_reauthenticate_after_disabling_and_reactivation(AccountStatus $status): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $user = User::factory()->create();
        $this->loginAndCapture($user);
        $guard = Auth::guard('web');
        $cookieName = $guard->getRecallerName();
        $cookie = $user->id.'|'.$user->fresh()->remember_token.'|'.$user->password;
        $this->replay([]);
        $this->withCredentials()->withCookie($cookieName, $cookie)->getJson('/api/me')->assertOk();
        $this->assertTrue(Auth::guard('web')->viaRemember());
        $this->assertSame(0, session('auth_session_version'));

        app(UserService::class)->changeStatus($user, $status, $admin);
        $this->replay([]);
        $this->getJson('/api/me')->assertUnauthorized();
        app(UserService::class)->changeStatus($user, AccountStatus::Active, $admin);
        $this->replay([]);
        $this->getJson('/api/me')->assertUnauthorized();
        $this->loginAndCapture($user);
    }

    public function test_deleted_user_session_is_rejected_even_with_a_loaded_guard_user(): void
    {
        $user = User::factory()->create();
        $payload = $this->loginAndCapture($user);
        $user->fresh()->delete();
        $this->getJson('/api/me')->assertForbidden();
        $this->replay($payload);
        $this->getJson('/api/me')->assertUnauthorized();
    }
}
