<?php

namespace App\Http\Responses;

use App\Services\ExchangedOidcNonce;
use App\Services\OidcIdToken;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

class OidcBearerTokenResponse extends BearerTokenResponse
{
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        $scopes = array_map(static fn ($scope): string => $scope->getIdentifier(), $accessToken->getScopes());
        // Refresh grants have no completed authorization-code context. Only add an
        // ID token to an openid authorization-code exchange, never plain OAuth.
        if (! in_array('openid', $scopes, true) || app(ExchangedOidcNonce::class)->authorizationCodeId() === null) {
            return [];
        }

        return ['id_token' => app(OidcIdToken::class)->issue($accessToken, $this->privateKey)];
    }
}
