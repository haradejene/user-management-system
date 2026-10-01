<?php

namespace App\Http\Controllers\Oidc;

use App\Services\OidcSigningKey;
use Illuminate\Http\JsonResponse;
use League\OAuth2\Server\Exception\OAuthServerException;

class JwksController
{
    public function __invoke(OidcSigningKey $keys): JsonResponse
    {
        try {
            return response()->json($keys->publishedKeys(), 200, [
                'Content-Type' => 'application/jwk-set+json',
                'Cache-Control' => 'public, max-age=300',
            ]);
        } catch (OAuthServerException) {
            // Even with APP_DEBUG enabled, do not leak key paths/configuration.
            return response()->json(['error' => 'server_error'], 503, ['Cache-Control' => 'no-store']);
        }
    }
}
