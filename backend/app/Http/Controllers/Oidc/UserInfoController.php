<?php

namespace App\Http\Controllers\Oidc;

use App\Services\OidcUserClaims;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserInfoController
{
    public function __invoke(Request $request, OidcUserClaims $claims): JsonResponse
    {
        $user = $request->user('oauth');
        // These scopes were validated by Passport, not supplied by this request.
        $scopes = $user->token()->oauth_scopes;
        if (! in_array('openid', $scopes, true)) {
            return response()->json(['error' => 'insufficient_scope'], 403, [
                'WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="openid"',
            ]);
        }

        return response()->json($claims->forUser($user, $scopes));
    }
}
