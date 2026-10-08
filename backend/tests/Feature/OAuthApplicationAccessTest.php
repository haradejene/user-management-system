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

class OAuthApplicationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_client_needs_no_administrator_and_receives_only_the_decision(): void
    {
        [$user, , , $tokens] = $this->issue();
        $this->assertFalse($user->isCentralIamAdministrator());
        $response = $this->check($tokens['access_token'])->assertOk()->assertExactJson(['effective' => true])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertStringNotContainsString($tokens['access_token'], $response->getContent());
        $this->assertStringNotContainsString($user->public_id, $response->getContent());
    }

    public static function invalidCredentials(): array
    {
        return [[null], ['Bearer '], ['Bearer not-a-token'], ['Basic not-a-token'], ['Bearer one, Bearer two']];
    }

    #[DataProvider('invalidCredentials')]
    public function test_missing_or_malformed_credentials_are_rejected(?string $header): void
    {
        $this->get('/oauth/application-access', $header === null ? [] : ['Authorization' => $header])
            ->assertUnauthorized()->assertExactJson(['error' => 'invalid_token'])
            ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_browser_admin_session_cookie_and_query_token_do_not_authenticate(): void
    {
        [$user, , , $tokens] = $this->issue();
        $user->forceFill(['is_system_admin' => true])->save();
        $this->actingAs($user, 'web')->get('/oauth/application-access?'.http_build_query(['access_token' => $tokens['access_token']]))
            ->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
        $cookie = app(ApiTokenCookieFactory::class)->make($user->id, 'browser-csrf');
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
            ->withHeader('X-CSRF-TOKEN', 'browser-csrf')->get('/oauth/application-access')
            ->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
    }

    public function test_expired_token_is_rejected(): void
    {
        $ttl = new DateInterval('PT1M');
        $ttl->invert = 1;
        Passport::tokensExpireIn($ttl);
        try {
            [, , , $tokens] = $this->issue();
            $this->check($tokens['access_token'])->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
        } finally {
            Passport::tokensExpireIn(new DateInterval('PT15M'));
        }
    }

    public static function credentialChanges(): array
    {
        return [['revoked'], ['client-revoked'], ['wrong-client'], ['wrong-user'], ['wrong-scopes'], ['row-expired']];
    }

    #[DataProvider('credentialChanges')]
    public function test_issued_record_and_authenticated_claims_must_match(string $change): void
    {
        [, $application, $client, $tokens] = $this->issue();
        $id = $this->claims($tokens['access_token'])['jti'];
        $otherClient = app(OAuthClientService::class)->create($application, ['name' => 'Other client', 'redirect_uris' => ['https://other.test/callback'], 'confidential' => false]);
        match ($change) {
            'revoked' => DB::table('oauth_access_tokens')->where('id', $id)->update(['revoked' => true]),
            'client-revoked' => $client->forceFill(['revoked' => true])->save(),
            'wrong-client' => DB::table('oauth_access_tokens')->where('id', $id)->update(['client_id' => $otherClient->id]),
            'wrong-user' => DB::table('oauth_access_tokens')->where('id', $id)->update(['user_id' => User::factory()->create()->id]),
            'wrong-scopes' => DB::table('oauth_access_tokens')->where('id', $id)->update(['scopes' => '[]']),
            'row-expired' => DB::table('oauth_access_tokens')->where('id', $id)->update(['expires_at' => now()->subMinute()]),
        };
        $this->check($tokens['access_token'])->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
    }

    public function test_id_token_and_changed_audience_or_subject_cannot_authenticate(): void
    {
        [, , , $tokens] = $this->issue();
        $this->check($tokens['id_token'])->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
        [$header, , $signature] = explode('.', $tokens['access_token']);
        foreach (['aud' => ['another-client'], 'sub' => 'another-user'] as $key => $value) {
            $claims = $this->claims($tokens['access_token']);
            $claims[$key] = $value;
            $payload = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            $this->check($header.'.'.$payload.'.'.$signature)->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
        }
    }

    public function test_even_a_valid_signature_cannot_rebind_an_issued_token_to_another_client_or_identity(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => base_path('tests/Fixtures/openssl.cnf')]);
        openssl_pkey_export($key, $private, null, ['config' => base_path('tests/Fixtures/openssl.cnf')]);
        config(['passport.private_key' => str_replace("\n", '\\n', $private), 'passport.public_key' => str_replace("\n", '\\n', openssl_pkey_get_details($key)['key'])]);
        [, $application, , $tokens] = $this->issue();
        $other = User::factory()->create();
        $other->applications()->attach($application, ['status' => 'active']);
        $otherClient = app(OAuthClientService::class)->create($application, ['name' => 'Other', 'redirect_uris' => ['https://other.test/callback'], 'confidential' => false]);
        [$header] = explode('.', $tokens['access_token']);
        foreach (['aud' => [$otherClient->id], 'sub' => (string) $other->id] as $field => $value) {
            $claims = $this->claims($tokens['access_token']);
            $claims[$field] = $value;
            $payload = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            openssl_sign($header.'.'.$payload, $signature, $key, OPENSSL_ALGO_SHA256);
            $jwt = $header.'.'.$payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
            $this->check($jwt)->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
        }
        $this->check($tokens['access_token'])->assertOk()->assertExactJson(['effective' => true]);
    }

    public static function lifecycleChanges(): array
    {
        return [
            ['user-inactive', 403], ['user-suspended', 403], ['user-deleted', 401],
            ['app-inactive', 403], ['app-deleted', 403], ['assignment-inactive', 403], ['assignment-removed', 403],
        ];
    }

    #[DataProvider('lifecycleChanges')]
    public function test_current_access_is_rechecked_without_disclosing_reasons(string $change, int $status): void
    {
        [$user, $application, , $tokens] = $this->issue();
        $this->check($tokens['access_token'])->assertOk()->assertExactJson(['effective' => true]);
        match ($change) {
            'user-inactive' => $user->update(['status' => 'inactive']),
            'user-suspended' => $user->update(['status' => 'suspended']),
            'user-deleted' => $user->delete(),
            'app-inactive' => $application->update(['status' => 'inactive']),
            'app-deleted' => $application->delete(),
            'assignment-inactive' => $user->applications()->updateExistingPivot($application->id, ['status' => 'inactive']),
            'assignment-removed' => $user->applications()->detach($application->id),
        };
        $this->check($tokens['access_token'])->assertStatus($status)
            ->assertExactJson(['error' => $status === 401 ? 'invalid_token' : 'access_denied'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_query_and_body_selectors_are_not_accepted_or_used_for_enumeration(): void
    {
        [, , , $tokens] = $this->issue();
        $other = User::factory()->create();
        $otherApplication = Application::factory()->create();
        foreach (['user_id' => $other->id, 'sub' => $other->public_id, 'subject' => $other->public_id,
            'email' => $other->email, 'issuer' => 'https://other.test', 'application_id' => $otherApplication->public_id,
            'client_id' => 'other-client', 'application_id[]' => 'nonexistent'] as $key => $value) {
            $this->check($tokens['access_token'], [$key => $value])->assertStatus(400)->assertExactJson(['error' => 'invalid_request']);
        }
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$tokens['access_token'])->json('GET', '/oauth/application-access', ['sub' => $other->public_id])
            ->assertStatus(400)->assertExactJson(['error' => 'invalid_request']);
    }

    public function test_other_identity_and_application_access_cannot_rescue_a_denied_caller_even_if_admin(): void
    {
        [$user, $application, , $tokens] = $this->issue();
        $other = User::factory()->create();
        $other->applications()->attach($application, ['status' => 'active']);
        $otherApplication = Application::factory()->create();
        $user->applications()->attach($otherApplication, ['status' => 'active']);
        $user->forceFill(['is_system_admin' => true])->save();
        $user->applications()->detach($application->id);
        $this->check($tokens['access_token'])->assertForbidden()->assertExactJson(['error' => 'access_denied']);
        $this->check($tokens['access_token'], ['sub' => $other->public_id, 'application_id' => $otherApplication->public_id])
            ->assertForbidden()->assertExactJson(['error' => 'access_denied']);
    }

    public function test_openid_scope_is_required_and_cannot_be_added_by_request(): void
    {
        [, , , $tokens] = $this->issue('iam:read');
        $this->check($tokens['access_token'], ['scope' => 'openid'])->assertForbidden()->assertExactJson(['error' => 'insufficient_scope'])
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="openid"');
    }

    private function issue(string $scopes = 'openid'): array
    {
        $user = User::factory()->create();
        $application = Application::factory()->create();
        $user->applications()->attach($application, ['status' => 'active']);
        $client = app(OAuthClientService::class)->create($application, ['name' => 'HRM access test', 'redirect_uris' => ['https://client.example.test/callback'], 'confidential' => false]);
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $verifier = str_repeat('v', 48);
        $query = ['response_type' => 'code', 'client_id' => $client->id, 'redirect_uri' => 'https://client.example.test/callback', 'scope' => $scopes, 'nonce' => str_contains($scopes, 'openid') ? 'access-bound-nonce' : null, 'prompt' => 'consent', 'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertOk();
        $approved = $this->post('/oauth/authorize', ['auth_token' => $this->app['session.store']->get('authToken')])->assertRedirect();
        parse_str(parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $callback);
        $tokens = $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $client->id, 'redirect_uri' => $query['redirect_uri'], 'code' => $callback['code'], 'code_verifier' => $verifier])->assertOk()->json();
        Auth::forgetGuards();
        $this->flushSession();

        return [$user, $application, $client, $tokens];
    }

    private function check(string $token, array $query = []): TestResponse
    {
        Auth::forgetGuards();
        Once::flush();

        return $this->get('/oauth/application-access'.($query ? '?'.http_build_query($query) : ''), ['Authorization' => 'Bearer '.$token]);
    }

    private function claims(string $jwt): array
    {
        return json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/'), true), true, 512, JSON_THROW_ON_ERROR);
    }
}
