<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Enums\ApplicationStatus;
use App\Models\OAuthClient;
use App\Services\ApplicationAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

            $request->attributes->remove('oidc_exchanged_nonce');
            $request->attributes->remove('oidc_exchanged_code_id');
            $request->attributes->set('oidc_token_exchange', $request->input('grant_type') === 'authorization_code');
            try {
                $response = $next($request);
                if ($response->getStatusCode() >= 400) {
                    $request->attributes->remove('oidc_exchanged_nonce');
                    $request->attributes->remove('oidc_exchanged_code_id');
                }

                return $response;
            } catch (\Throwable $exception) {
                $request->attributes->remove('oidc_exchanged_nonce');
                $request->attributes->remove('oidc_exchanged_code_id');
                throw $exception;
            } finally {
                $request->attributes->remove('oidc_token_exchange');
            }
        }

        if (! $request->is('oauth/authorize') || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $clientId = $request->query('client_id');
        if (! Str::isUuid($clientId)) {
            return response()->json(['error' => 'invalid_client'], 400);
        }
        $client = OAuthClient::query()->with('application')->find($clientId);

        if (! $client || $client->revoked || ! $client->hasGrantType('authorization_code')) {
            return response()->json(['error' => 'invalid_client'], 400);
        }

        $redirectUri = $request->query('redirect_uri');
        if (! is_string($redirectUri) || $redirectUri === '' || ! in_array($redirectUri, $client->redirect_uris ?? [], true)) {
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
        $allowedScopes = ['iam:read', 'openid', 'profile', 'email'];
        if ($requestedScopes->diff($allowedScopes)->isNotEmpty()
            || ($requestedScopes->intersect(['profile', 'email'])->isNotEmpty() && ! $requestedScopes->contains('openid'))) {
            return response()->json(['error' => 'invalid_scope'], 400);
        }

        $nonce = $request->query('nonce');
        if ($requestedScopes->contains('openid') && (! is_string($nonce) || $nonce === '' || strlen($nonce) > 255)) {
            return response()->json(['error' => 'invalid_request', 'error_description' => 'nonce is required for OpenID Connect.'], 400);
        }

        $application = $client->application;
        if (! $application || $application->trashed() || $application->status !== ApplicationStatus::Active) {
            return response()->json(['error' => 'unauthorized_client'], 403);
        }

        $user = $request->user('web');
        if ($user && ($user->trashed() || $user->status !== AccountStatus::Active || ! $this->access->isAllowed($user, $application))) {
            return response()->json(['error' => 'access_denied'], 403);
        }

        if ($requestedScopes->contains('openid')) {
            if (config('session.driver') === 'cookie') {
                throw new \LogicException('OIDC authorization requires server-side sessions.');
            }
            $request->attributes->set('oidc_transaction', [
                'client_id' => (string) $client->getKey(),
                // Only used to bind the pending nonce to Passport's authorization request.
                // Never persisted on the code or used to validate token exchange.
                'redirect_uri' => $redirectUri,
                'nonce' => $nonce,
            ]);
        }

        return $next($request);
    }
}
