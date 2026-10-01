<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TypeError;

class RequireOidcBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // Passport also supports browser cookies. UserInfo accepts only bearer
        // credentials and must never fall back to a transient browser token.
        $user = null;
        if (preg_match('/^Bearer [^\s,]+$/iD', $request->header('Authorization', '')) === 1) {
            try {
                $user = $request->user('oauth');
            } catch (TypeError) {
                // Passport's resource validator requires an access-token jti.
                // A signed ID token lacks it and cannot authenticate UserInfo.
            }
        }
        if (! $user) {
            return response()->json(['error' => 'invalid_token'], 401, [
                'WWW-Authenticate' => 'Bearer error="invalid_token"',
                'Cache-Control' => 'no-store',
            ]);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
