<?php

namespace App\Http\Controllers\Oidc;

use App\Http\Middleware\OidcAuthorizationTransaction;
use App\Http\Middleware\SelectOAuthAccount;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ChangeAccountController
{
    public function __invoke(Request $request): Response
    {
        $token = $request->input('auth_token');
        $pending = $request->session()->get(OidcAuthorizationTransaction::SESSION_KEY, []);
        $transaction = is_string($token) ? ($pending[$token] ?? null) : null;
        if (! $transaction || ($transaction['consent_token'] ?? null) !== $token
            || ($transaction['expires_at'] ?? 0) <= time() || ! isset($transaction['query'])) {
            return response()->view('oauth.cancelled', [], 410)->header('Cache-Control', 'no-store');
        }
        unset($pending[$token]);
        $request->session()->put(OidcAuthorizationTransaction::SESSION_KEY, $pending);
        if ($request->session()->get('authToken') === $token) {
            $request->session()->forget(['authToken', 'authRequest']);
        }

        // Keep the exact validated request, including an explicit consent prompt.
        return SelectOAuthAccount::offer($request, $transaction['query']);
    }
}
