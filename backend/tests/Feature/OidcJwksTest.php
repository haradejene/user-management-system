<?php

namespace Tests\Feature;

use App\Services\OidcSigningKey;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Exception\OAuthServerException;
use Tests\TestCase;

class OidcJwksTest extends TestCase
{
    public function test_jwks_is_public_session_free_and_contains_only_one_public_rs256_key(): void
    {
        $response = $this->get('/oauth/jwks')->assertOk()->assertHeader('Content-Type', 'application/jwk-set+json');
        $this->assertCount(1, $response->json('keys'));
        $this->assertSame(['keys'], array_keys($response->json()));
        $jwk = $response->json('keys.0');
        $this->assertSame(['kty', 'use', 'alg', 'kid', 'n', 'e'], array_keys($jwk));
        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame('RS256', $jwk['alg']);
        foreach (['kid', 'n', 'e'] as $parameter) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $jwk[$parameter]);
        }
        $this->assertSame('AQAB', $jwk['e']);
        $canonical = json_encode(['e' => $jwk['e'], 'kty' => 'RSA', 'n' => $jwk['n']], JSON_UNESCAPED_SLASHES);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $canonical, true)), '+/', '-_'), '='), $jwk['kid']);
        $this->assertNull($response->getCookie(config('session.cookie')));
        $this->assertNotContains(StartSession::class, Route::gatherRouteMiddleware(Route::getRoutes()->getByName('oidc.jwks')));
        $this->assertStringNotContainsString('PRIVATE KEY', $response->getContent());
        $this->assertStringNotContainsString(file_get_contents(Passport::keyPath('oauth-private.key')), $response->getContent());
        foreach (['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'key', 'path', 'issuer'] as $privateOrInternal) {
            $this->assertArrayNotHasKey($privateOrInternal, $jwk);
        }
    }

    public function test_requests_and_issuer_cannot_change_published_key_or_algorithm(): void
    {
        $expected = $this->get('/oauth/jwks')->assertOk()->json();
        $this->get('/oauth/jwks?alg=HS256&kid=attacker&client_id=missing&private_key=attacker')->assertOk()->assertExactJson($expected);
        $this->get('/oauth/authorize?client_id=missing')->assertBadRequest();
        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'kid' => 'attacker', 'alg' => 'none'])->assertBadRequest();
        config(['oidc.issuer' => 'https://changed.example.test', 'app.url' => 'https://another.example.test']);
        $this->get('/oauth/jwks')->assertOk()->assertExactJson($expected);
        $this->assertSame($expected, app(OidcSigningKey::class)->publishedKeys());
    }

    public function test_inline_private_key_and_pem_formatting_resolve_to_the_same_jwk(): void
    {
        $expected = $this->get('/oauth/jwks')->assertOk()->json();
        $pem = file_get_contents(Passport::keyPath('oauth-private.key'));
        config(['passport.private_key' => str_replace("\n", '\\n', str_replace("\r", '', $pem)), 'passport.public_key' => 'unrelated-public-setting']);
        $this->get('/oauth/jwks')->assertOk()->assertExactJson($expected);
        config(['passport.private_key' => str_replace("\n", "\r\n", str_replace("\r", '', $pem))]);
        $this->get('/oauth/jwks')->assertOk()->assertExactJson($expected);
    }

    public function test_invalid_key_configuration_returns_generic_uncacheable_errors(): void
    {
        config(['app.debug' => true]);
        foreach (['missing-secret-marker', 'file://'.storage_path('missing-private-key.pem'), file_get_contents(Passport::keyPath('oauth-public.key'))] as $invalid) {
            config(['passport.private_key' => $invalid]);
            $response = $this->get('/oauth/jwks')->assertStatus(503)->assertExactJson(['error' => 'server_error']);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringNotContainsString('missing-secret-marker', $response->getContent());
            $this->assertStringNotContainsString(storage_path(), $response->getContent());
        }
    }

    public function test_non_rsa_and_weak_rsa_keys_are_not_published(): void
    {
        foreach ([['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'], ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]] as $options) {
            $key = openssl_pkey_new([...$options, 'config' => base_path('tests/Fixtures/openssl.cnf')]);
            openssl_pkey_export($key, $pem, null, ['config' => base_path('tests/Fixtures/openssl.cnf')]);
            config(['passport.private_key' => $pem]);
            $this->get('/oauth/jwks')->assertStatus(503)->assertExactJson(['error' => 'server_error']);
        }
    }

    public function test_a_mismatched_loaded_signing_key_is_rejected(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => base_path('tests/Fixtures/openssl.cnf')]);
        openssl_pkey_export($key, $pem, null, ['config' => base_path('tests/Fixtures/openssl.cnf')]);
        $this->expectException(OAuthServerException::class);
        $this->expectExceptionMessage('The OpenID Connect signing key is unavailable.');
        app(OidcSigningKey::class)->keyId(new CryptKey($pem));
    }
}
