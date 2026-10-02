<?php

namespace App\Repositories;

use App\Services\OAuthAccessEligibility;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Passport\Bridge\RefreshTokenRepository as PassportRefreshTokenRepository;
use Laravel\Passport\Passport;

class IamRefreshTokenRepository extends PassportRefreshTokenRepository
{
    public function __construct(Dispatcher $events, private readonly OAuthAccessEligibility $eligibility)
    {
        parent::__construct($events);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        if (Passport::refreshToken()->getConnection()->transactionLevel() === 0) {
            throw new \LogicException('Refresh-token exchange requires a transaction.');
        }
        $refreshToken = Passport::refreshToken()->newQuery()->whereKey($tokenId)->lockForUpdate()->first();

        if (! $refreshToken || $refreshToken->revoked || ($refreshToken->expires_at && $refreshToken->expires_at->isPast())) {
            return true;
        }

        $accessToken = Passport::token()->newQuery()->find($refreshToken->access_token_id);
        $eligible = $accessToken && $this->eligibility->user((string) $accessToken->user_id, (string) $accessToken->client_id, true);

        if (! $eligible) {
            $this->revokeRefreshToken($tokenId);
            // Validation stops before issuance. Preserve the existing permanent
            // revocation when current IAM eligibility rejects this refresh.
            app('request')->attributes->set('oauth_refresh_eligibility_rejected', true);

            return true;
        }

        return false;
    }
}
