<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Enums\ApplicationStatus;
use App\Models\OAuthClient;
use App\Services\ApplicationAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateOAuthAuthorization
{
    public function __construct(private readonly ApplicationAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('oauth/token') && $request->isMethod('POST')) {
            if (! in_array($request->input('grant_type'), ['authorization_code', 'refresh_token'], true)) {
                return response()->json(['error' => 'unsupported_grant_type'], 400);
            }

            return $next($request);
        }

        if (! $request->is('oauth/authorize') || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $client = OAuthClient::query()->with('application')->find($request->query('client_id'));

        if (! $client || $client->revoked || ! $client->hasGrantType('authorization_code')) {
            return response()->json(['error' => 'invalid_client'], 400);
        }

        $redirectUri = (string) $request->query('redirect_uri');
        if ($redirectUri === '' || ! in_array($redirectUri, $client->redirect_uris ?? [], true)) {
            return response()->json(['error' => 'invalid_request', 'error_description' => 'The redirect URI is not registered.'], 400);
        }

        if ($request->query('response_type') !== 'code') {
            return response()->json(['error' => 'unsupported_response_type'], 400);
        }

        if ($request->query('code_challenge_method') !== 'S256' || blank($request->query('code_challenge'))) {
            return response()->json(['error' => 'invalid_request', 'error_description' => 'S256 PKCE is required.'], 400);
        }

        $requestedScopes = collect(explode(' ', (string) $request->query('scope')))
            ->filter()
            ->unique();
        if ($requestedScopes->diff(['iam:read'])->isNotEmpty()) {
            return response()->json(['error' => 'invalid_scope'], 400);
        }

        $application = $client->application;
        if (! $application || $application->trashed() || $application->status !== ApplicationStatus::Active) {
            return response()->json(['error' => 'unauthorized_client'], 403);
        }

        $user = $request->user('web');
        if ($user && ($user->trashed() || $user->status !== AccountStatus::Active || ! $this->access->isAllowed($user, $application))) {
            return response()->json(['error' => 'access_denied'], 403);
        }

        return $next($request);
    }
}
