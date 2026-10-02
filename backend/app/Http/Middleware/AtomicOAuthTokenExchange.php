<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

class AtomicOAuthTokenExchange
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('oauth/token') || ! $request->isMethod('POST')) {
            return $next($request);
        }

        // Repositories lock the authoritative code/refresh row only after
        // Passport decrypts it. Keep that lock through validation, issuance,
        // revocation and ID-token rendering, without parsing OAuth payloads here.
        $connection = Passport::token()->getConnection();
        $request->attributes->remove('oauth_refresh_eligibility_rejected');
        $connection->beginTransaction();
        try {
            $response = $next($request);
            if ($response->isSuccessful() || $request->attributes->get('oauth_refresh_eligibility_rejected') === true) {
                $connection->commit();
            } else {
                $connection->rollBack();
            }

            return $response;
        } catch (\Throwable $exception) {
            // This rejection happens before Passport can issue any token; its
            // only write is the existing eligibility-driven refresh revocation.
            if ($request->attributes->get('oauth_refresh_eligibility_rejected') === true) {
                $connection->commit();
            } else {
                $connection->rollBack();
            }
            throw $exception;
        } finally {
            $request->attributes->remove('oauth_refresh_eligibility_rejected');
        }
    }
}
