<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Services\OAuthClientService;
use DateInterval;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ApiTokenCookieFactory;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OidcUserInfoTest extends TestCase
{
    use RefreshDatabase;

    public static function invalidCredentials(): array
    {
        return [
            'missing' => [null],
            'empty bearer' => ['Bearer '],
            'malformed JWT' => ['Bearer not-a-token'],
            'wrong scheme' => ['Basic not-a-token'],
            'multiple credentials' => ['Bearer one, Bearer two'],
        ];
    }

    #[DataProvider('invalidCredentials')]
    public function test_invalid_credentials_return_a_safe_json_error(?string $header): void
    {
        $headers = $header === null ? [] : ['Authorization' => $header];
        $response = $this->get('/oauth/userinfo', $headers)->assertUnauthorized()
            ->assertExactJson(['error' => 'invalid_token'])
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('trace', $response->getContent());
    }

    public function test_browser_session_and_query_token_cannot_authenticate_userinfo(): void
    {
        [$user, , , $tokens] = $this->issue();
        $this->actingAs($user, 'web')->get('/oauth/userinfo?'.http_build_query(['access_token' => $tokens['access_token']]))
            ->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
        $cookie = app(ApiTokenCookieFactory::class)->make($user->id, 'browser-csrf');
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->withHeader('X-CSRF-TOKEN', 'browser-csrf')->get('/oauth/userinfo')
            ->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
    }

    public function test_id_token_and_tampered_access_token_cannot_authenticate(): void
    {
        [, , , $tokens] = $this->issue();
        $this->userinfo($tokens['id_token'])->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
        [$header, $payload, $signature] = explode('.', $tokens['access_token']);
        $claims = $this->claims($tokens['access_token']);
        $claims['sub'] = 'another-user';
        $payload = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $this->userinfo($header.'.'.$payload.'.'.$signature)->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
    }

    public function test_expired_access_token_is_rejected_by_passport(): void
    {
        $ttl = new DateInterval('PT1M');
        $ttl->invert = 1;
        Passport::tokensExpireIn($ttl);
        try {
            [, , , $tokens] = $this->issue();
            $this->assertLessThan(time(), $this->claims($tokens['access_token'])['exp']);
            $this->userinfo($tokens['access_token'])->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
        } finally {
            Passport::tokensExpireIn(new DateInterval('PT15M'));
        }
    }

    public function test_revoked_access_token_is_rejected(): void
    {
        [, , , $tokens] = $this->issue();
        DB::table('oauth_access_tokens')->where('id', $this->claims($tokens['access_token'])['jti'])->update(['revoked' => true]);
        $this->userinfo($tokens['access_token'])->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
    }

    public function test_revoked_client_is_rejected_even_when_the_token_row_is_not_revoked(): void
    {
        [, , $client, $tokens] = $this->issue();
        $client->forceFill(['revoked' => true])->save();
        $this->assertDatabaseHas('oauth_access_tokens', ['client_id' => $client->id, 'revoked' => false]);
        $this->userinfo($tokens['access_token'])->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
    }

    public static function lifecycleChanges(): array
    {
        return [
            'inactive user' => ['user-inactive', 403],
            'suspended user' => ['user-suspended', 403],
            'deleted user' => ['user-deleted', 401],
            'inactive application' => ['app-inactive', 403],
            'deleted application' => ['app-deleted', 403],
            'inactive assignment' => ['assignment-inactive', 403],
            'revoked assignment' => ['assignment-removed', 403],
        ];
    }

    #[DataProvider('lifecycleChanges')]
    public function test_current_eligibility_is_checked_on_every_request(string $change, int $status): void
    {
        [$user, $application, , $tokens] = $this->issue();
        $this->userinfo($tokens['access_token'])->assertOk()->assertExactJson(['sub' => $user->public_id]);
        match ($change) {
            'user-inactive' => $user->update(['status' => 'inactive']),
            'user-suspended' => $user->update(['status' => 'suspended']),
            'user-deleted' => $user->delete(),
            'app-inactive' => $application->update(['status' => 'inactive']),
            'app-deleted' => $application->delete(),
            'assignment-inactive' => $user->applications()->updateExistingPivot($application->id, ['status' => 'inactive']),
            'assignment-removed' => $user->applications()->detach($application->id),
        };
        $this->assertDatabaseHas('oauth_access_tokens', ['client_id' => $tokens['client_id'], 'revoked' => false]);
        $this->userinfo($tokens['access_token'])->assertStatus($status)
            ->assertExactJson(['error' => $status === 401 ? 'invalid_token' : 'access_denied']);
    }

    public static function grantedScopes(): array
    {
        return [
            'openid only' => ['openid'],
            'email' => ['openid email'],
            'profile' => ['openid profile'],
            'both' => ['openid email profile'],
        ];
    }

    #[DataProvider('grantedScopes')]
    public function test_claims_are_scope_gated_and_match_id_token_identity(string $scopes): void
    {
        [$user, , , $tokens] = $this->issue($scopes, true);
        $expected = ['sub' => $user->public_id];
        if (str_contains($scopes, 'email')) {
            $expected += ['email' => $user->email, 'email_verified' => true];
        }
        if (str_contains($scopes, 'profile')) {
            $expected += ['name' => $user->name, 'given_name' => 'Ada', 'family_name' => 'Lovelace', 'picture' => 'https://images.example.test/ada.png'];
        }
        $response = $this->userinfo($tokens['access_token'])->assertOk()->assertExactJson($expected)
            ->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'no-store, private');
        $idClaims = $this->claims($tokens['id_token']);
        $this->assertSame($idClaims['sub'], $response->json('sub'));
        $this->assertNotSame((string) $user->id, $response->json('sub'));
        $this->assertNotSame($user->email, $response->json('sub'));
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $idClaims[$key]);
        }
        // Exact JSON above is also an allowlist: no admin, assignments, roles,
        // company, session, nonce, token, secret or numeric identity fields.
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_user_cannot_be_selected_or_scopes_elevated_by_request_parameters(): void
    {
        [$user, , , $tokens] = $this->issue();
        $other = User::factory()->create();
        $this->userinfo($tokens['access_token'], ['user_id' => $other->id, 'sub' => $other->public_id, 'email' => $other->email, 'scope' => 'openid profile email'])
            ->assertOk()->assertExactJson(['sub' => $user->public_id]);
        $this->withHeader('Authorization', 'Bearer '.$tokens['access_token'])->json('GET', '/oauth/userinfo', ['user_id' => $other->id, 'sub' => $other->public_id])
            ->assertOk()->assertExactJson(['sub' => $user->public_id]);
    }

    public function test_missing_profile_values_and_private_paths_are_omitted(): void
    {
        [$user, , , $tokens] = $this->issue('openid profile');
        $this->userinfo($tokens['access_token'])->assertOk()->assertExactJson(['sub' => $user->public_id, 'name' => $user->name]);
        foreach (['private/photos/person.png', 'C:\\private\\person.png', 'file:///private/person.png', 'javascript:alert(1)', ''] as $path) {
            $user->profile()->updateOrCreate([], ['first_name' => '', 'last_name' => null, 'profile_photo' => $path]);
            $this->userinfo($tokens['access_token'])->assertOk()->assertExactJson(['sub' => $user->public_id, 'name' => $user->name]);
        }
    }

    public function test_email_and_verification_state_are_current(): void
    {
        [$user, , , $tokens] = $this->issue('openid email');
        $user->forceFill(['email' => 'new@example.test', 'email_verified_at' => null])->save();
        $this->userinfo($tokens['access_token'])->assertOk()->assertExactJson(['sub' => $user->public_id, 'email' => 'new@example.test', 'email_verified' => false]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->userinfo($tokens['access_token'])->assertOk()->assertJsonPath('email_verified', true);
    }

    public function test_plain_oauth_token_requires_openid_scope(): void
    {
        [, , , $tokens] = $this->issue('iam:read');
        $this->assertArrayNotHasKey('id_token', $tokens);
        $this->userinfo($tokens['access_token'])->assertForbidden()->assertExactJson(['error' => 'insufficient_scope'])
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="openid"');
    }

    public function test_post_userinfo_is_bearer_only_and_does_not_require_csrf(): void
    {
        [$user, , , $tokens] = $this->issue();
        $this->withMiddleware(VerifyCsrfToken::class);
        $other = User::factory()->create();
        $this->postJson('/oauth/userinfo', ['access_token' => $tokens['access_token']])
            ->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
        $this->withHeader('Authorization', 'Bearer '.$tokens['access_token'])
            ->postJson('/oauth/userinfo', ['sub' => $other->public_id, 'user_id' => $other->id, 'scope' => 'profile email'])
            ->assertOk()->assertExactJson(['sub' => $user->public_id]);
    }

    public function test_refresh_token_preserves_userinfo_scope_and_identity(): void
    {
        [$user, , $client, $tokens] = $this->issue('openid email');
        $refresh = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->id, 'refresh_token' => $tokens['refresh_token']])->assertOk();
        $this->assertArrayNotHasKey('id_token', $refresh->json());
        $this->userinfo($refresh->json('access_token'))->assertOk()
            ->assertExactJson(['sub' => $user->public_id, 'email' => $user->email, 'email_verified' => true]);
    }

    private function issue(string $scopes = 'openid', bool $profile = false): array
    {
        $user = User::factory()->create();
        if ($profile) {
            $user->forceFill(['is_system_admin' => true])->save();
            $user->profile()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'profile_photo' => 'https://images.example.test/ada.png']);
        }
        $application = Application::factory()->create();
        $user->applications()->attach($application, ['status' => 'active']);
        $client = app(OAuthClientService::class)->create($application, ['name' => 'UserInfo test', 'redirect_uris' => ['https://client.example.test/callback'], 'confidential' => false]);
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $verifier = str_repeat('v', 48);
        $query = ['response_type' => 'code', 'client_id' => $client->id, 'redirect_uri' => 'https://client.example.test/callback', 'scope' => $scopes, 'nonce' => str_contains($scopes, 'openid') ? 'userinfo-bound-nonce' : null, 'prompt' => 'consent', 'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertOk();
        $approved = $this->post('/oauth/authorize', ['auth_token' => $this->app['session.store']->get('authToken')])->assertRedirect();
        parse_str(parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $callback);
        $tokens = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $client->id, 'redirect_uri' => $query['redirect_uri'], 'code' => $callback['code'], 'code_verifier' => $verifier])->assertOk()->json();
        Auth::forgetGuards();
        $this->flushSession();

        return [$user, $application, $client, [...$tokens, 'client_id' => $client->id]];
    }

    private function userinfo(string $token, array $query = []): TestResponse
    {
        // Each call represents a separate bearer-only HTTP request.
        Auth::forgetGuards();
        Once::flush();

        return $this->get('/oauth/userinfo?'.http_build_query($query), ['Authorization' => 'Bearer '.$token]);
    }

    private function claims(string $jwt): array
    {
        return json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/'), true), true, 512, JSON_THROW_ON_ERROR);
    }
}
