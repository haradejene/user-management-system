<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\OidcAuthorizationTransaction;
use App\Models\Application;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\OAuthClientService;
use App\Services\UserService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OAuthBrowserSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/login']);
    }

    public static function disablingStates(): array
    {
        return ['inactive' => [AccountStatus::Inactive], 'suspended' => [AccountStatus::Suspended]];
    }

    public function test_current_login_can_consent_and_exchange_an_oidc_code(): void
    {
        [$user, $client] = $this->fixture();
        $this->login($user);
        $this->pending($client);
        $this->exchange($client, $this->post('/oauth/authorize', ['auth_token' => session('authToken')]))
            ->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'id_token']);
    }

    #[DataProvider('disablingStates')]
    public function test_disabled_login_cannot_start_authorization(AccountStatus $status): void
    {
        [$user, $client, $admin] = $this->fixture();
        $old = $this->login($user);
        app(UserService::class)->changeStatus($user, $status, $admin);
        $this->replay($old);
        $this->assertRejected($this->get($this->url($client)), 403);
    }

    #[DataProvider('disablingStates')]
    public function test_reactivation_requires_fresh_login_before_authorization(AccountStatus $status): void
    {
        [$user, $client, $admin] = $this->fixture();
        $old = $this->login($user);
        app(UserService::class)->changeStatus($user, $status, $admin);
        app(UserService::class)->changeStatus($user, AccountStatus::Active, $admin);
        $this->replay($old);
        $this->assertRejected($this->get($this->url($client)), 401);
        $this->assertGuest('web');
        $this->login($user);
        $this->assertSame(1, session('auth_session_version'));
        $this->pending($client);
        $this->exchange($client, $this->post('/oauth/authorize', ['auth_token' => session('authToken')]))->assertOk()->assertJsonStructure(['id_token']);
    }

    public function test_deleted_user_cannot_authorize_with_an_old_session(): void
    {
        [$user, $client] = $this->fixture();
        $old = $this->login($user);
        $user->delete();
        $this->replay($old);
        // A fresh web guard cannot resolve a soft-deleted user: Passport requires login.
        $response = $this->get($this->url($client))->assertRedirect();
        $this->assertStringNotContainsString('code=', $response->headers->get('Location', ''));
        $this->assertStringNotContainsString('id_token', $response->getContent());
        $this->assertGuest('web');
        $this->assertNoCredentials();
    }

    #[DataProvider('disablingStates')]
    public function test_remember_cookie_cannot_restore_oauth_authentication_after_reactivation(AccountStatus $status): void
    {
        [$user, $client, $admin] = $this->fixture();
        $this->login($user);
        $guard = $this->app['auth']->guard('web');
        $cookie = $user->id.'|'.$user->fresh()->remember_token.'|'.$user->password;
        $this->replay([]);
        $this->withCredentials()->withCookie($guard->getRecallerName(), $cookie);
        $this->pending($client);
        $this->assertTrue($this->app['auth']->guard('web')->viaRemember());
        $this->assertSame(0, session('auth_session_version'));
        app(UserService::class)->changeStatus($user, $status, $admin);
        app(UserService::class)->changeStatus($user, AccountStatus::Active, $admin);
        $this->replay([]);
        $this->assertTrue($guard->getProvider()->retrieveByToken($user->id, explode('|', $cookie)[1]) === null);
        $this->assertNull(session($guard->getName()));
        $this->get($this->url($client))->assertRedirect();
        $this->assertGuest('web');
        $this->assertNoCredentials();
    }

    public function test_missing_session_stamp_cannot_authorize(): void
    {
        [$user, $client] = $this->fixture();
        $old = $this->login($user);
        unset($old['auth_session_version']);
        $this->replay($old);
        $this->assertRejected($this->get($this->url($client)), 401);
    }

    public function test_guest_login_continuation_completes_the_original_oidc_request(): void
    {
        [$user, $client] = $this->fixture();
        $this->get($this->url($client))->assertRedirect();
        $this->get('/login')->assertRedirect('http://localhost:3000/login?oauth_continue=1');
        $this->login($user);
        $continued = $this->get('/oauth/continue')->assertRedirect();
        $this->get($continued->headers->get('Location'))->assertOk();
        $response = $this->post('/oauth/authorize', ['auth_token' => session('authToken')]);
        $this->exchange($client, $response)->assertOk()->assertJsonStructure(['id_token']);
    }

    public static function consentActions(): array
    {
        return ['approve' => ['POST'], 'deny' => ['DELETE']];
    }

    #[DataProvider('consentActions')]
    public function test_stale_session_cannot_use_pending_consent_or_denial(string $method): void
    {
        [$user, $client, $admin] = $this->fixture();
        $this->login($user);
        $first = $this->pending($client, 'first');
        $second = $this->pending($client, 'second');
        $old = session()->all();
        $this->assertCount(2, session(OidcAuthorizationTransaction::SESSION_KEY));
        app(UserService::class)->changeStatus($user, AccountStatus::Inactive, $admin);
        app(UserService::class)->changeStatus($user, AccountStatus::Active, $admin);
        $this->replay($old);
        $this->assertRejected($this->call($method, '/oauth/authorize', ['auth_token' => $first]), 401);
        // Both requests belonged to the invalidated browser session; neither survives.
        $this->assertNull(session(OidcAuthorizationTransaction::SESSION_KEY));
        $this->assertNull(session('authToken'));
        $this->login($user);
        $this->post('/oauth/authorize', ['auth_token' => $second])->assertForbidden();
        $this->assertNoCredentials();
    }

    public function test_stale_session_cannot_take_the_auto_approval_path(): void
    {
        [$user, $client, $admin] = $this->fixture();
        $this->login($user);
        $this->pending($client);
        $approved = $this->post('/oauth/authorize', ['auth_token' => session('authToken')]);
        $this->exchange($client, $approved)->assertOk();
        $old = session()->all();
        app(UserService::class)->changeStatus($user, AccountStatus::Inactive, $admin);
        app(UserService::class)->changeStatus($user, AccountStatus::Active, $admin);
        $this->replay($old);
        $response = $this->get($this->url($client, 'auto', false))->assertUnauthorized();
        $this->assertStringNotContainsString('code=', $response->headers->get('Location', ''));
        $this->assertStringNotContainsString('id_token', $response->getContent());
        $this->assertDatabaseCount('oauth_auth_codes', 1);
        $this->assertDatabaseCount('oauth_access_tokens', 1);
        $this->assertDatabaseCount('oauth_refresh_tokens', 1);
        $this->assertGuest('web');
    }

    public function test_stale_session_cannot_continue_an_intended_authorization(): void
    {
        [$user, $client, $admin] = $this->fixture();
        $old = $this->login($user);
        $old['url.intended'] = 'http://localhost'.$this->url($client);
        app(UserService::class)->changeStatus($user, AccountStatus::Inactive, $admin);
        app(UserService::class)->changeStatus($user, AccountStatus::Active, $admin);
        $this->replay($old);
        $this->assertRejected($this->get('/oauth/continue'), 401);
        $this->assertNull(session('url.intended'));
    }

    public function test_valid_concurrent_transactions_keep_their_own_nonce_and_session_lock(): void
    {
        [$user, $client] = $this->fixture();
        $this->login($user);
        $first = $this->pending($client, 'first');
        $second = $this->pending($client, 'second');
        $this->post('/oauth/authorize', ['auth_token' => $first])->assertRedirect();
        $this->assertArrayHasKey($second, session(OidcAuthorizationTransaction::SESSION_KEY));
        $this->post('/oauth/authorize', ['auth_token' => $second])->assertRedirect();
        $this->assertDatabaseHas('oauth_auth_codes', ['nonce' => 'first']);
        $this->assertDatabaseHas('oauth_auth_codes', ['nonce' => 'second']);
        $this->assertSame([], session(OidcAuthorizationTransaction::SESSION_KEY));
        foreach (['authorize', 'approve', 'deny'] as $action) {
            $route = Route::getRoutes()->getByName('passport.authorizations.'.$action);
            $this->assertSame(30, $route->locksFor());
            $this->assertContains(EnsureAccountIsActive::class.':oauth', app('router')->gatherRouteMiddleware($route));
        }
    }

    public function test_invalidation_after_middleware_is_rechecked_before_code_persistence(): void
    {
        [$user, $client, $admin] = $this->fixture();
        $this->login($user);
        $token = $this->pending($client);
        $this->app->instance(InvalidateOAuthSessionDuringApproval::class, new InvalidateOAuthSessionDuringApproval($user, $admin));
        Route::getRoutes()->getByName('passport.authorizations.approve')->middleware(InvalidateOAuthSessionDuringApproval::class);
        $response = $this->post('/oauth/authorize', ['auth_token' => $token]);
        $this->assertRejected($response, 401);
        $response->assertJsonPath('error', 'access_denied');
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertNull(session(OidcAuthorizationTransaction::SESSION_KEY.'.'.$token));
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $application = Application::factory()->create();
        $user->applications()->attach($application, ['status' => 'active']);
        $client = app(OAuthClientService::class)->create($application, ['name' => 'Session regression', 'redirect_uris' => ['https://client.example.test/callback']]);

        return [$user, $client, User::factory()->systemAdmin()->create()];
    }

    private function login(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password', 'remember' => true])->assertOk();
        $this->assertSame($user->fresh()->session_version, session('auth_session_version'));

        return session()->all();
    }

    private function replay(array $payload): void
    {
        $this->app['auth']->forgetGuards();
        // Laravel's in-process HTTP tests retain controller constructor guards.
        // A replay models a new browser request, including a new Passport guard.
        Route::getRoutes()->getByName('passport.authorizations.authorize')->flushController();
        $this->withSession([]);
        session()->flush();
        session()->put($payload);
        session()->save();
    }

    private function url(OAuthClient $client, string $nonce = 'session-nonce', bool $consent = true): string
    {
        return '/oauth/authorize?'.http_build_query([
            'client_id' => $client->id, 'redirect_uri' => $client->redirect_uris[0], 'response_type' => 'code',
            'scope' => 'openid', 'nonce' => $nonce, 'state' => 'session-state', 'prompt' => $consent ? 'consent' : null,
            'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 48), true)), '+/', '-_'), '='),
        ]);
    }

    private function pending(OAuthClient $client, string $nonce = 'session-nonce'): string
    {
        $this->get($this->url($client, $nonce))->assertOk();

        return session('authToken');
    }

    private function exchange(OAuthClient $client, TestResponse $response): TestResponse
    {
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('session-state', $query['state']);

        return $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client->id, 'redirect_uri' => $client->redirect_uris[0],
            'code' => $query['code'], 'code_verifier' => str_repeat('v', 48),
        ]);
    }

    private function assertRejected(TestResponse $response, int $status): void
    {
        $response->assertStatus($status);
        $this->assertStringNotContainsString('id_token', $response->getContent());
        $this->assertStringNotContainsString('code=', $response->headers->get('Location', ''));
        $this->assertNoCredentials();
    }

    private function assertNoCredentials(): void
    {
        $this->assertDatabaseCount('oauth_auth_codes', 0);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertDatabaseCount('oauth_refresh_tokens', 0);
    }
}

// Executes after the web session check, before Passport completes approval.
class InvalidateOAuthSessionDuringApproval
{
    public function __construct(private User $user, private User $admin) {}

    public function handle(Request $request, Closure $next): mixed
    {
        app(UserService::class)->changeStatus($this->user, AccountStatus::Inactive, $this->admin);
        app(UserService::class)->changeStatus($this->user, AccountStatus::Active, $this->admin);

        return $next($request);
    }
}
