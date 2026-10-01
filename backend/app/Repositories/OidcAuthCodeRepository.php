<?php

namespace App\Repositories;

use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

class OidcAuthCodeRepository extends PassportAuthCodeRepository
{
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $scopes = array_map(static fn ($scope): string => $scope->getIdentifier(), $authCodeEntity->getScopes());
        if (! in_array('openid', $scopes, true)) {
            parent::persistNewAuthCode($authCodeEntity);

            return;
        }

        $transaction = app('request')->attributes->get('oidc_transaction');
        if (! is_array($transaction)
            || ($transaction['client_id'] ?? null) !== (string) $authCodeEntity->getClient()->getIdentifier()
            || ($transaction['redirect_uri'] ?? null) !== $authCodeEntity->getRedirectUri()
            || ! is_string($transaction['nonce'] ?? null)
            || $transaction['nonce'] === '' || strlen($transaction['nonce']) > 255) {
            throw new \RuntimeException('The OpenID Connect authorization transaction is invalid.');
        }

        // Passport inserts through this same model connection. Keep its implementation
        // and include metadata in the transaction; a failed write cannot leave a code.
        Passport::authCode()->getConnection()->transaction(function () use ($authCodeEntity, $transaction): void {
            parent::persistNewAuthCode($authCodeEntity);
            $code = Passport::authCode()->newQuery()->findOrFail($authCodeEntity->getIdentifier());
            $code->nonce = $transaction['nonce'];
            if (! $code->save()) {
                throw new \RuntimeException('Unable to persist the OpenID Connect nonce.');
            }
        });
        app('request')->attributes->remove('oidc_transaction');
    }

    public function revokeAuthCode(string $codeId): void
    {
        parent::revokeAuthCode($codeId);

        // League calls this after code/client/redirect/PKCE validation and token
        // issuance. isAuthCodeRevoked is too early: PKCE has not yet been checked.
        $request = app('request');
        if ($request->attributes->get('oidc_token_exchange') === true) {
            $request->attributes->set('oidc_exchanged_nonce', Passport::authCode()->newQuery()->find($codeId)?->nonce);
            $request->attributes->set('oidc_exchanged_code_id', $codeId);
        }
    }
}
