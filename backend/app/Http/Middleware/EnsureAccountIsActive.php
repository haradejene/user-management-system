<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next, ?string $context = null): Response
    {
        if ($context === 'oauth' && ! $request->is('oauth/authorize', 'oauth/continue')) {
            return $next($request);
        }

        $user = $request->user($context === 'oauth' ? 'web' : null);
        // Guests must still reach Passport's central-login continuation.
        if ($context === 'oauth' && ! $user) {
            return $next($request);
        }
        $current = $user && ! $user->trashed() ? User::query()->find($user->getKey()) : null;
        $active = $current?->status === AccountStatus::Active;
        $validSession = $current && $this->hasCurrentSession($request, $current);

        if (! $active || ! $validSession) {
            Auth::guard('web')->logoutCurrentDevice();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return new JsonResponse([
                'message' => $active ? 'Your session has expired. Please log in again.' : 'Your account is not active.',
            ], $active ? Response::HTTP_UNAUTHORIZED : Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    public function hasCurrentSession(Request $request, User $user): bool
    {
        return $request->hasSession() && $request->session()->get('auth_session_version') === $user->session_version;
    }
}
