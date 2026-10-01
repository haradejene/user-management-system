<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Exceptions\InvalidAuthTokenException;

class OidcAuthorizationTransaction
{
    public const SESSION_KEY = 'oidc_authorization_transactions';

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            return $this->handleTransaction($request, $next);
        } catch (\Throwable $exception) {
            // StartSession does not save when downstream throws. Persist terminal
            // cleanup before Laravel renders the exception outside this pipeline.
            if ($request->is('oauth/authorize') && $request->hasSession()) {
                $request->session()->save();
            }
            throw $exception;
        }
    }

    private function handleTransaction(Request $request, Closure $next): mixed
    {
        if (! $request->is('oauth/authorize')) {
            return $next($request);
        }

        $session = $request->session();
        // Discard the obsolete single-slot format, never migrate an unbound nonce.
        $session->forget('oidc_authorization_transaction');
        $pending = $session->get(self::SESSION_KEY, []);
        foreach ($pending as $token => $transaction) {
            if (($transaction['expires_at'] ?? 0) <= time()) {
                unset($pending[$token]);
            }
        }
        $session->put(self::SESSION_KEY, $pending);

        if (! $request->isMethod('GET')) {
            $activeToken = $session->get('authToken');
            $token = $request->input('auth_token');
            $transaction = is_string($token) ? ($pending[$token] ?? null) : null;
            if ($transaction && ($transaction['consent_token'] ?? null) !== $token) {
                $session->forget(self::SESSION_KEY.'.'.$token);
                throw InvalidAuthTokenException::different();
            }
            if ($transaction) {
                // Passport's random consent token selects server-side data, never nonce
                // from the form. Passport still checks and consumes this exact token.
                $session->put('authToken', $token);
                $session->put('authRequest', $transaction['auth_request']);
                $request->attributes->set('oidc_transaction', $transaction['metadata']);
            }
            try {
                return $next($request);
            } finally {
                if (is_string($token)) {
                    $session->forget(self::SESSION_KEY.'.'.$token);
                }
                $request->attributes->remove('oidc_transaction');
                // Invalid consent invalidates Passport's active single slot too.
                if (! $transaction && is_string($activeToken)) {
                    $session->forget(self::SESSION_KEY.'.'.$activeToken);
                }
            }
        }

        $previousToken = $session->get('authToken');
        try {
            $response = $next($request);
            $metadata = $request->attributes->get('oidc_transaction');
            $token = $session->get('authToken');
            if ($metadata && $response->getStatusCode() === 200 && is_string($token)
                && $token !== $previousToken && is_string($session->get('authRequest'))) {
                $session->put(self::SESSION_KEY.'.'.$token, [
                    'consent_token' => $token,
                    'metadata' => $metadata,
                    'auth_request' => $session->get('authRequest'),
                    'expires_at' => time() + 600,
                ]);
            }

            return $response;
        } finally {
            // Login continues via the original query and recreates metadata after
            // validation. Errors, auto-approval and prompt=none retain no new entry.
            $request->attributes->remove('oidc_transaction');
        }
    }
}
