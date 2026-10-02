<?php

namespace App\Repositories;

use App\Services\OAuthAccessEligibility;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Passport\Bridge\AccessTokenRepository;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;

class IamAccessTokenRepository extends AccessTokenRepository
{
    public function __construct(Dispatcher $events, private readonly OAuthAccessEligibility $eligibility)
    {
        parent::__construct($events);
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $this->eligibility->requireUser($accessTokenEntity->getUserIdentifier(), $accessTokenEntity->getClient()->getIdentifier());
        parent::persistNewAccessToken($accessTokenEntity);
    }
}
