<?php

declare(strict_types=1);

namespace Doxa\Laravel\Oidc;

use Doxa\Laravel\Contracts\Transport;
use Doxa\Laravel\Exceptions\UserInfoException;
use Doxa\Laravel\Identity\DoxaIdentity;
use Doxa\Laravel\Token\TokenResponse;
use Doxa\Laravel\Transaction\AuthorizationTransaction;

final class UserInfo
{
    public function __construct(private readonly Transport $http) {}

    /** @return array<string, mixed> */
    public function fetch(#[\SensitiveParameter] DoxaIdentity $identity, #[\SensitiveParameter] TokenResponse $tokens, #[\SensitiveParameter] AuthorizationTransaction $transaction): array
    {
        try {
            if (! in_array('openid', $tokens->scopes, true) || $transaction->provider->userInfoEndpoint === null
                || $identity->issuer() !== $transaction->issuer || $transaction->provider->issuer !== $transaction->issuer) {
                throw new UserInfoException('userinfo_failure');
            }
            $response = $this->http->request('GET', $transaction->provider->userInfoEndpoint,
                ['headers' => ['Authorization' => 'Bearer '.$tokens->accessToken(), 'Accept' => 'application/json']]);
            if ($response->mediaType !== 'application/json') {
                throw new UserInfoException('userinfo_failure');
            }
            $data = $response->data();
            if (! is_string($data['sub'] ?? null) || $data['sub'] !== $identity->subject()) {
                throw new UserInfoException('userinfo_failure');
            }

            return $data;
        } catch (\Throwable) {
            throw new UserInfoException('userinfo_failure');
        }
    }
}
