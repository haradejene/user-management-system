<?php

declare(strict_types=1);

namespace Doxa\Laravel\Http;

use Closure;
use Doxa\Laravel\Exceptions\DoxaException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Opt-in callback middleware; registers no routes and establishes no session. */
final class SecureCallbackResponse
{
    public function handle(#[\SensitiveParameter] Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (DoxaException $exception) {
            $response = new JsonResponse(['error' => $exception->category], 400);
        }
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
