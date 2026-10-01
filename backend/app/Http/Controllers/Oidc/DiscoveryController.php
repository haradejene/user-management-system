<?php

namespace App\Http\Controllers\Oidc;

use App\Services\OidcDiscovery;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class DiscoveryController
{
    public function __invoke(OidcDiscovery $discovery): JsonResponse
    {
        try {
            return response()->json($discovery->metadata(), 200, ['Cache-Control' => 'public, max-age=300']);
        } catch (RuntimeException) {
            return response()->json(['error' => 'server_error'], 503, ['Cache-Control' => 'no-store']);
        }
    }
}
