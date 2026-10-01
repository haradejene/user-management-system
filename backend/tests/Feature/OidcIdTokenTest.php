<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\OAuthClientService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OidcIdTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_id_tokens_are_signed_and_claims_are_gated_by_granted_scopes(): void
    {
        [$user, , $client] = $this->fixture();
        $user->profile()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'profile_photo' => 'https://images.example.test/ada.png']);
        config(['oidc.issuer' => 'https://iam.example.test']);
        foreach (['openid', 'openid email', 'openid profile', 'openid email profile'] as $scopes) {
            $token = $this->authorize($user, $client, $scopes, 'bound-'.$scopes);
            $before = time();
            $response = $this->postJson('/oauth/token', [...$token, 'nonce' => 'attacker-nonce', 'scope' => 'openid profile email', 'sub' => 'attacker', 'iss' => 'https://evil.test', 'aud' => 'evil']);
            $response->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'id_token', 'expires_in']);
            $jwt = $response->json('id_token');
            [$header, $claims] = $this->decode($jwt);
            $this->assertSame('RS256', $header['alg']);
            $this->assertSame(1, $this->verifySignature($jwt));
            $this->assertSame($this->get('/oauth/jwks')->json('keys.0.kid'), $header['kid']);
            $this->assertSame('https://iam.example.test', $claims['iss']);
            $discovery = $this->get('/.well-known/openid-configuration')->assertOk()->json();
            $this->assertSame($claims['iss'], $discovery['issuer']);
            $this->assertSame([$header['alg']], $discovery['id_token_signing_alg_values_supported']);
            $this->assertSame($user->public_id, $claims['sub']);
            $this->assertNotSame((string) $user->id, $claims['sub']);
            $this->assertNotSame($user->email, $claims['sub']);
            $this->assertSame([$client->id], (array) $claims['aud']);
            $this->assertSame('bound-'.$scopes, $claims['nonce']);
            $this->assertIsInt($claims['iat']);
            $this->assertIsInt($claims['exp']);
            $this->assertGreaterThanOrEqual($before, $claims['iat']);
            $this->assertLessThanOrEqual(time(), $claims['iat']);
            $this->assertGreaterThan($claims['iat'], $claims['exp']);
            $this->assertLessThanOrEqual(900, $claims['exp'] - $claims['iat']);
            if (str_contains($scopes, 'email')) {
                $this->assertSame($user->email, $claims['email']);
                $this->assertTrue($claims['email_verified']);
            } else {
                $this->assertArrayNotHasKey('email', $claims);
                $this->assertArrayNotHasKey('email_verified', $claims);
            }
            foreach (['name' => $user->name, 'given_name' => 'Ada', 'family_name' => 'Lovelace', 'picture' => 'https://images.example.test/ada.png'] as $key => $value) {
                if (str_contains($scopes, 'profile')) {
                    $this->assertSame($value, $claims[$key]);
                } else {
                    $this->assertArrayNotHasKey($key, $claims);
                }
            }
            $this->assertSame([], array_diff(array_keys($claims), ['iss', 'sub', 'aud', 'iat', 'exp', 'nonce', 'email', 'email_verified', 'name', 'given_name', 'family_name', 'picture']));
            $this->assertArrayNotHasKey('nonce', $response->json());
        }
    }

    public function test_public_subject_is_stable_when_email_changes_and_verification_is_false(): void
    {
        [$user, , $client] = $this->fixture();
        config(['oidc.issuer' => null, 'app.url' => 'https://stable.example.test']);
        $first = $this->postJson('/oauth/token', $this->authorize($user, $client, 'openid email', 'first'))->assertOk();
        $user->forceFill(['email' => 'changed@example.test', 'email_verified_at' => null])->save();
        $second = $this->postJson('/oauth/token', $this->authorize($user, $client, 'openid email', 'second'))->assertOk();
        [, $oldClaims] = $this->decode($first->json('id_token'));
        [, $claims] = $this->decode($second->json('id_token'));
        $this->assertSame($oldClaims['sub'], $claims['sub']);
        $this->assertSame($user->public_id, $claims['sub']);
        $this->assertSame('changed@example.test', $claims['email']);
        $this->assertFalse($claims['email_verified']);
        $this->assertSame('https://stable.example.test', $claims['iss']);
    }

    public function test_unavailable_profile_claims_and_local_photo_paths_are_omitted(): void
    {
        [$user, , $client] = $this->fixture();
        foreach ([null, 'private/photos/user.png', 'https://images.example.test/user.png'] as $photo) {
            if ($photo !== null) {
                $user->profile()->updateOrCreate([], ['profile_photo' => $photo]);
            }
            $response = $this->postJson('/oauth/token', $this->authorize($user, $client, 'openid profile', 'profile-nonce'))->assertOk();
            [, $claims] = $this->decode($response->json('id_token'));
            $this->assertSame($user->name, $claims['name']);
            $this->assertArrayNotHasKey('given_name', $claims);
            $this->assertArrayNotHasKey('family_name', $claims);
            if ($photo === 'https://images.example.test/user.png') {
                $this->assertSame($photo, $claims['picture']);
            } else {
                $this->assertArrayNotHasKey('picture', $claims);
            }
        }
    }

    public function test_plain_oauth_and_refresh_responses_do_not_gain_id_tokens(): void
    {
        [$user, , $client] = $this->fixture();
        $oauth = $this->postJson('/oauth/token', [...$this->authorize($user, $client, 'iam:read', null), 'nonce' => 'not-oidc'])->assertOk();
        $this->assertArrayNotHasKey('id_token', $oauth->json());
        [$accessHeader] = $this->decode($oauth->json('access_token'));
        $this->assertArrayNotHasKey('kid', $accessHeader);
        $this->assertSame(['access_token', 'expires_in', 'refresh_token', 'token_type'], array_values(Arr::sort(array_keys($oauth->json()))));
        $oidc = $this->postJson('/oauth/token', $this->authorize($user, $client, 'openid', 'initial'))->assertOk();
        $refresh = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->id, 'refresh_token' => $oidc->json('refresh_token'), 'nonce' => 'not-a-new-authentication'])->assertOk();
        $this->assertArrayNotHasKey('id_token', $refresh->json());
    }

    public function test_failed_exchange_and_replay_do_not_issue_id_tokens(): void
    {
        [$user, , $client] = $this->fixture();
        $token = $this->authorize($user, $client, 'openid email', 'validated');
        foreach ([['redirect_uri' => 'https://evil.test'], ['code_verifier' => str_repeat('x', 48)]] as $invalid) {
            $response = $this->postJson('/oauth/token', [...$token, ...$invalid])->assertBadRequest();
            $this->assertArrayNotHasKey('id_token', $response->json());
        }
        $this->postJson('/oauth/token', $token)->assertOk()->assertJsonStructure(['id_token']);
        $replay = $this->postJson('/oauth/token', $token)->assertBadRequest();
        $this->assertArrayNotHasKey('id_token', $replay->json());
    }

    public function test_missing_bound_nonce_fails_closed_instead_of_using_token_request_nonce(): void
    {
        [$user, , $client] = $this->fixture();
        $token = $this->authorize($user, $client, 'openid', 'bound');
        DB::table('oauth_auth_codes')->where('client_id', $client->id)->update(['nonce' => null]);
        $response = $this->postJson('/oauth/token', [...$token, 'nonce' => 'replacement'])->assertStatus(500);
        $response->assertJsonPath('error', 'server_error');
        $this->assertArrayNotHasKey('id_token', $response->json());
    }

    public function test_concurrent_authorizations_use_their_own_client_and_nonce_in_id_tokens(): void
    {
        [$user, $application, $client] = $this->fixture();
        $other = app(OAuthClientService::class)->create($application, ['name' => 'Other', 'redirect_uris' => ['https://client.example.test/callback'], 'confidential' => false]);
        $first = $this->pending($user, $client, 'openid', 'first-client');
        $second = $this->pending($user, $other, 'openid', 'second-client');
        foreach ([[$first, $client, 'first-client'], [$second, $other, 'second-client']] as [$pending, $expectedClient, $nonce]) {
            $response = $this->postJson('/oauth/token', $this->complete($pending))->assertOk();
            [, $claims] = $this->decode($response->json('id_token'));
            $this->assertSame($nonce, $claims['nonce']);
            $this->assertSame([$expectedClient->id], (array) $claims['aud']);
        }
    }

    public function test_configured_inline_signing_key_matches_jwks_and_client_algorithm_is_ignored(): void
    {
        $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => base_path('tests/Fixtures/openssl.cnf')]);
        openssl_pkey_export($rsa, $pem, null, ['config' => base_path('tests/Fixtures/openssl.cnf')]);
        config(['passport.private_key' => str_replace("\n", '\\n', $pem)]);
        [$user, , $client] = $this->fixture();
        $token = $this->authorize($user, $client, 'openid', 'bound-inline-key');
        $response = $this->postJson('/oauth/token', [...$token, 'alg' => 'HS256', 'kid' => 'client-controlled'])->assertOk();
        [$header, $claims] = $this->decode($response->json('id_token'));
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame($this->get('/oauth/jwks')->assertOk()->json('keys.0.kid'), $header['kid']);
        $this->assertSame(1, $this->verifySignature($response->json('id_token')));
        $this->assertSame('bound-inline-key', $claims['nonce']);
        $this->assertSame($user->public_id, $claims['sub']);
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $application = Application::factory()->create();
        $user->applications()->attach($application, ['status' => 'active']);
        $client = app(OAuthClientService::class)->create($application, ['name' => 'OIDC test', 'redirect_uris' => ['https://client.example.test/callback'], 'confidential' => false]);
        $this->withoutMiddleware(VerifyCsrfToken::class);

        return [$user, $application, $client];
    }

    private function authorize(User $user, OAuthClient $client, string $scopes, ?string $nonce): array
    {
        return $this->complete($this->pending($user, $client, $scopes, $nonce));
    }

    private function pending(User $user, OAuthClient $client, string $scopes, ?string $nonce): array
    {
        $verifier = str_repeat('v', 48);
        $query = ['response_type' => 'code', 'client_id' => $client->id, 'redirect_uri' => 'https://client.example.test/callback', 'scope' => $scopes, 'nonce' => $nonce, 'prompt' => 'consent', 'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertOk();

        return ['client_id' => $client->id, 'verifier' => $verifier, 'auth_token' => $this->app['session.store']->get('authToken')];
    }

    private function complete(array $pending): array
    {
        $approved = $this->post('/oauth/authorize', ['auth_token' => $pending['auth_token'], 'nonce' => 'untrusted-consent-nonce'])->assertRedirect();
        parse_str(parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $query);

        return ['grant_type' => 'authorization_code', 'client_id' => $pending['client_id'], 'redirect_uri' => 'https://client.example.test/callback', 'code' => $query['code'], 'code_verifier' => $pending['verifier']];
    }

    private function decode(string $jwt): array
    {
        [$header, $claims] = explode('.', $jwt);

        return [json_decode($this->base64UrlDecode($header), true, 512, JSON_THROW_ON_ERROR), json_decode($this->base64UrlDecode($claims), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function verifySignature(string $jwt): int
    {
        [$header, $claims, $signature] = explode('.', $jwt);

        $jwk = $this->get('/oauth/jwks')->assertOk()->json('keys.0');
        // Reconstruct a public RSA PEM using only published n/e, never local keys.
        $n = $this->derInteger($this->base64UrlDecode($jwk['n']));
        $e = $this->derInteger($this->base64UrlDecode($jwk['e']));
        $der = "\x30".$this->derLength(strlen($n.$e)).$n.$e;
        $pem = "-----BEGIN RSA PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END RSA PUBLIC KEY-----\n";

        return openssl_verify($header.'.'.$claims, $this->base64UrlDecode($signature), $pem, OPENSSL_ALGO_SHA256);
    }

    private function derInteger(string $bytes): string
    {
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\0".$bytes;
        }

        return "\x02".$this->derLength(strlen($bytes)).$bytes;
    }

    private function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\0");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
