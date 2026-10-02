<?php

namespace App\Repositories;

use Illuminate\Support\Str;
use Laravel\Passport\Bridge\ClientRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\ClientEntityInterface;

class IamClientRepository extends ClientRepository
{
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        // Passport resolves body/Basic credentials before this boundary. Its
        // normal invalid-client handling must run before a UUID database lookup.
        if (! Str::isUuid($clientIdentifier)) {
            return null;
        }

        if (app('request')->is('oauth/token') && Passport::client()->getConnection()->transactionLevel() > 0) {
            // Passport validates the client before locking the code/refresh row.
            // Shared locks keep revocation ordered client -> credentials, while
            // allowing competing redemptions to reach the credential row lock.
            $client = Passport::client()->newQuery()->whereKey($clientIdentifier)->sharedLock()->first();

            return $client && ! $client->revoked ? $this->fromClientModel($client) : null;
        }

        return parent::getClientEntity($clientIdentifier);
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        return Str::isUuid($clientIdentifier) && parent::validateClient($clientIdentifier, $clientSecret, $grantType);
    }
}
