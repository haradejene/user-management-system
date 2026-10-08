<?php

namespace App\Http\Controllers\Oidc;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationAccessController
{
    public function __invoke(Request $request): JsonResponse
    {
        // No selectors: authentication and current access were evaluated by the
        // same resource middleware as UserInfo, using the OAuth guard context.
        if ($request->query->count() !== 0 || $request->getContent() !== '') {
            return response()->json(['error' => 'invalid_request'], 400);
        }

        return response()->json(['effective' => true]);
    }
}
