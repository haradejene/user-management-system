<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCentralIamAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isCentralIamAdministrator()) {
            return new JsonResponse([
                'message' => 'You are not authorized to access the IAM administration interface.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
