<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Services\OAuthClientService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OidcDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_is_public_json_session_free_and_cacheable(): void
    {
        $response = $this->get('/.well-known/openid-configuration')->assertOk()
            ->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'max-age=300, public');
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $middleware = app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('oidc.discovery'));
        $this->assertSame([], $middleware);
        $this->assertDatabaseCount('oauth_auth_codes', 0);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_metadata_contains_only_implemented_capabilities_and_actual_routes(): void
    {
        config(['oidc.issuer' => 'https://iam.example.test']);
        $response = $this->get('/.well-known/openid-configuration')->assertOk();
        $response->assertExactJson([
            'issuer' => 'https://iam.example.test',
            'authorization_endpoint' => 'https://iam.example.test/oauth/authorize',
            'token_endpoint' => 'https://iam.example.test/oauth/token',
            'userinfo_endpoint' => 'https://iam.example.test/oauth/userinfo',
            'jwks_uri' => 'https://iam.example.test/oauth/jwks',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['iam:read', 'openid', 'profile', 'email'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
            'request_uri_parameter_supported' => false,
            'claims_supported' => ['iss', 'sub', 'aud', 'exp', 'iat', 'nonce', 'name', 'given_name', 'family_name', 'picture', 'email', 'email_verified'],
        ]);
        foreach (['authorization_endpoint' => ['passport.authorizations.authorize', 'GET'], 'token_endpoint' => ['passport.token', 'POST'], 'userinfo_endpoint' => ['oidc.userinfo', 'GET'], 'jwks_uri' => ['oidc.jwks', 'GET']] as $field => [$name, $method]) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertSame('/'.$route->uri(), parse_url($response->json($field), PHP_URL_PATH));
            $this->assertSame($name, Route::getRoutes()->match(Request::create($response->json($field), $method))->getName());
        }
        $this->assertSame(Passport::scopeIds(), $response->json('scopes_supported'));
        $this->assertSame($response->json('id_token_signing_alg_values_supported'), [$this->get('/oauth/jwks')->assertOk()->json('keys.0.alg')]);
        $this->get('/oauth/userinfo')->assertUnauthorized();
        $this->postJson('/oauth/token', ['grant_type' => 'client_credentials'])->assertBadRequest()->assertJsonPath('error', 'unsupported_grant_type');
    }

    public function test_app_url_fallback_and_exact_issuer_with_base_path_are_preserved(): void
    {
        config(['oidc.issuer' => null, 'app.url' => 'http://localhost:8000']);
        $this->get('/.well-known/openid-configuration')->assertOk()->assertJsonPath('issuer', 'http://localhost:8000')
            ->assertJsonPath('token_endpoint', 'http://localhost:8000/oauth/token');
        config(['oidc.issuer' => 'https://iam.example.test:8443/identity/']);
        $this->get('/.well-known/openid-configuration')->assertOk()->assertJsonPath('issuer', 'https://iam.example.test:8443/identity/')
            ->assertJsonPath('token_endpoint', 'https://iam.example.test:8443/identity/oauth/token');
    }

    public function test_request_headers_and_parameters_cannot_change_metadata_or_expose_secrets(): void
    {
        config(['oidc.issuer' => 'https://iam.example.test', 'app.debug' => true, 'app.key' => 'private-app-secret-marker', 'passport.private_key' => 'private-key-marker', 'database.connections.pgsql.password' => 'db-secret-marker']);
        $expected = $this->get('/.well-known/openid-configuration')->assertOk()->json();
        $response = $this->get('https://attacker.example/.well-known/openid-configuration?issuer=https://evil.test&scope=roles&alg=none', ['X-Forwarded-Host' => 'evil.test', 'X-Forwarded-Proto' => 'http', 'Authorization' => 'Bearer not-a-token'])->assertOk()->assertExactJson($expected);
        foreach (['private-app-secret-marker', 'private-key-marker', 'db-secret-marker', 'BEGIN PRIVATE KEY', 'BEGIN RSA PRIVATE KEY', 'SQLSTATE'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        foreach (['userinfo_signing_alg_values_supported', 'userinfo_encryption_alg_values_supported', 'userinfo_encryption_enc_values_supported', 'registration_endpoint', 'end_session_endpoint', 'id_token_encryption_alg_values_supported'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json());
        }
    }

    public static function invalidIssuers(): array
    {
        return [[''], ['not-a-url'], ['https://secret:password@example.test'], ['https://example.test?secret=password'], ['https://example.test#fragment'], ['file:///private/issuer'], [123]];
    }

    #[DataProvider('invalidIssuers')]
    public function test_invalid_configuration_returns_generic_uncacheable_errors(mixed $issuer): void
    {
        config(['oidc.issuer' => $issuer, 'app.debug' => true]);
        $this->get('/.well-known/openid-configuration')->assertStatus(503)->assertExactJson(['error' => 'server_error'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function authenticationMethods(): array
    {
        return [['none'], ['client_secret_basic'], ['client_secret_post']];
    }

    #[DataProvider('authenticationMethods')]
    public function test_advertised_client_authentication_methods_work_with_pkce(string $method): void
    {
        $metadata = $this->get('/.well-known/openid-configuration')->assertOk()->json();
        $this->assertContains($method, $metadata['token_endpoint_auth_methods_supported']);
        $user = User::factory()->create();
        $application = Application::factory()->create();
        $user->applications()->attach($application, ['status' => 'active']);
        $client = app(OAuthClientService::class)->create($application, ['name' => 'Discovery test', 'redirect_uris' => ['https://client.example.test/callback'], 'confidential' => $method !== 'none']);
        $verifier = str_repeat('v', 48);
        $query = ['response_type' => $metadata['response_types_supported'][0], 'client_id' => $client->id, 'redirect_uri' => 'https://client.example.test/callback', 'scope' => 'openid', 'nonce' => 'discovery-bound', 'prompt' => 'consent', 'code_challenge_method' => $metadata['code_challenge_methods_supported'][0], 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
        $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertOk();
        $approved = $this->withoutMiddleware(VerifyCsrfToken::class)->post('/oauth/authorize', ['auth_token' => $this->app['session.store']->get('authToken')])->assertRedirect();
        parse_str(parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $callback);
        $token = ['grant_type' => 'authorization_code', 'redirect_uri' => $query['redirect_uri'], 'code' => $callback['code'], 'code_verifier' => $verifier];
        $headers = [];
        if ($method === 'client_secret_basic') {
            $headers['Authorization'] = 'Basic '.base64_encode($client->id.':'.$client->plainSecret);
        } else {
            $token['client_id'] = $client->id;
            if ($method === 'client_secret_post') {
                $token['client_secret'] = $client->plainSecret;
            }
        }
        $response = $this->postJson('/oauth/token', $token, $headers)->assertOk();
        [$header, $claims] = explode('.', $response->json('id_token'));
        $decode = static fn ($value) => json_decode(base64_decode(strtr($value, '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($metadata['issuer'], $decode($claims)['iss']);
        $this->assertContains($decode($header)['alg'], $metadata['id_token_signing_alg_values_supported']);
        $this->assertSame([], array_diff(array_keys($decode($claims)), $metadata['claims_supported']));
        $refresh = ['grant_type' => 'refresh_token', 'refresh_token' => $response->json('refresh_token')];
        if (isset($token['client_id'])) {
            $refresh['client_id'] = $token['client_id'];
        }
        if (isset($token['client_secret'])) {
            $refresh['client_secret'] = $token['client_secret'];
        }
        $this->postJson('/oauth/token', $refresh, $headers)->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    }
}
