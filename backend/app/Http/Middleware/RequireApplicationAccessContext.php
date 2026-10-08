<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

class RequireApplicationAccessContext
{
    public function handle(Request $request, Closure $next): Response
    {
        // The preceding bearer-only middleware validates the signature/time/jti.
        // Bind its authenticated claims to the issued record as well: neither a
        // caller-supplied client nor a mismatched signed audience selects an app.
        $user = $request->user('oauth');
        $client = Auth::guard('oauth')->client();
        $token = $user?->token();
        $issued = $token instanceof AccessToken
            ? Passport::token()->newQuery()->find($token->oauth_access_token_id)
            : null;

        if (! $issued || ! $client || $client->revoked || $issued->revoked
            || (string) $issued->user_id !== (string) $user->getAuthIdentifier()
            || (string) $issued->client_id !== (string) $client->getKey()
            || (string) $token->oauth_client_id !== (string) $client->getKey()
            || $issued->expires_at === null || $issued->expires_at->isPast()
            || $issued->scopes !== $token->oauth_scopes) {
            return response()->json(['error' => 'invalid_token'], 401, [
                'WWW-Authenticate' => 'Bearer error="invalid_token"',
            ]);
        }

        if (! in_array('openid', $token->oauth_scopes, true)) {
            return response()->json(['error' => 'insufficient_scope'], 403, [
                'WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="openid"',
            ]);
        }

        return $next($request);
    }
}
