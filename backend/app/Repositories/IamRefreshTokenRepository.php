<?php

namespace App\Repositories;

use App\Enums\AccountStatus;
use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\ApplicationAccessService;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Passport\Bridge\RefreshTokenRepository as PassportRefreshTokenRepository;
use Laravel\Passport\Passport;

class IamRefreshTokenRepository extends PassportRefreshTokenRepository
{
    public function __construct(Dispatcher $events, private readonly ApplicationAccessService $access)
    {
        parent::__construct($events);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $refreshToken = Passport::refreshToken()->newQuery()->find($tokenId);

        if (! $refreshToken || $refreshToken->revoked || ($refreshToken->expires_at && $refreshToken->expires_at->isPast())) {
            return true;
        }

        $accessToken = Passport::token()->newQuery()->find($refreshToken->access_token_id);
        $client = $accessToken ? OAuthClient::query()->find($accessToken->client_id) : null;
        $application = $client
            ? Application::withTrashed()->find($client->application_id)
            : null;
        $user = $accessToken
            ? User::withTrashed()->find($accessToken->user_id)
            : null;

        $eligible = $user
            && ! $user->trashed()
            && $user->status === AccountStatus::Active
            && $client
            && ! $client->revoked
            && $application
            && ! $application->trashed()
            && $application->status === ApplicationStatus::Active
            && $this->access->isAllowed($user, $application);

        if (! $eligible) {
            $this->revokeRefreshToken($tokenId);

            return true;
        }

        return false;
    }
}
