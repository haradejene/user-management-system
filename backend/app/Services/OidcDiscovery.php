<?php

namespace App\Services;

use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use RuntimeException;

class OidcDiscovery
{
    public function metadata(): array
    {
        // Identical resolution to ID tokens; preserve the exact issuer string.
        $issuer = config('oidc.issuer') ?? config('app.url');
        $parts = is_string($issuer) ? parse_url($issuer) : false;
        if (! is_string($issuer) || ! filter_var($issuer, FILTER_VALIDATE_URL) || ! $parts
            || ! in_array($parts['scheme'] ?? null, ['https', 'http'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('OIDC issuer configuration is unavailable.');
        }

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $this->endpoint($issuer, 'passport.authorizations.authorize'),
            'token_endpoint' => $this->endpoint($issuer, 'passport.token'),
            'userinfo_endpoint' => $this->endpoint($issuer, 'oidc.userinfo'),
            'jwks_uri' => $this->endpoint($issuer, 'oidc.jwks'),
            // Only flows admitted by ValidateOAuthAuthorization are published.
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => Passport::scopeIds(),
            // Passport's inspected client credential boundary accepts both
            // confidential methods; registered public clients need no secret.
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
            // In Discovery, omitted request_uri support defaults to true.
            'request_uri_parameter_supported' => false,
            'claims_supported' => ['iss', 'sub', 'aud', 'exp', 'iat', 'nonce', 'name', 'given_name', 'family_name', 'picture', 'email', 'email_verified'],
        ];
    }

    private function endpoint(string $issuer, string $name): string
    {
        $route = Route::getRoutes()->getByName($name);
        if (! $route || str_contains($route->uri(), '{')) {
            throw new RuntimeException('OIDC endpoint configuration is unavailable.');
        }

        // Read actual route paths without invoking request-based URL generation.
        return rtrim($issuer, '/').'/'.ltrim($route->uri(), '/');
    }
}
