<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\OAuthClient;
use App\Services\ApplicationAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureOAuthApplicationAccess
{
    public function __construct(private readonly ApplicationAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('oauth');
        $user = $request->user('oauth');
        $client = $guard->client();

        if (! $user || ! $client || $client->revoked) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        $oauthClient = OAuthClient::query()->find($client->getKey());
        $application = $oauthClient
            ? Application::withTrashed()->find($oauthClient->application_id)
            : null;

        if (
            $user->trashed()
            || $user->status !== AccountStatus::Active
            || ! $application
            || $application->trashed()
            || $application->status !== ApplicationStatus::Active
            || ! $this->access->isAllowed($user, $application)
        ) {
            return response()->json(['error' => 'access_denied'], 403);
        }

        return $next($request);
    }
}
