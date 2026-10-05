<?php

declare(strict_types=1);

namespace Doxa\Laravel\Token;

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Contracts\TransactionStore;
use Doxa\Laravel\Contracts\Transport;
use Doxa\Laravel\Exceptions\TokenExchangeException;
use Doxa\Laravel\Transaction\AuthorizationTransaction;

final class CodeExchange
{
    public function __construct(private readonly DoxaConfig $config, private readonly Transport $http, private readonly TransactionStore $transactions) {}

    public function exchange(#[\SensitiveParameter] string $code, #[\SensitiveParameter] AuthorizationTransaction $transaction): TokenResponse
    {
        if ($transaction->issuer !== $this->config->issuer || $transaction->clientId !== $this->config->clientId
            || $transaction->redirectUri !== $this->config->redirectUri || $transaction->scopes !== $this->config->scopes) {
            throw new TokenExchangeException('token_exchange_failure');
        }
        $this->transactions->consume($transaction);
        try {
            if (! in_array($this->config->authMethod, $transaction->provider->authMethods, true)) {
                throw new TokenExchangeException('unsupported_provider_capability');
            }
            $form = ['grant_type' => 'authorization_code', 'code' => $code,
                'redirect_uri' => $transaction->redirectUri, 'client_id' => $transaction->clientId,
                'code_verifier' => $transaction->verifier()];
            $headers = ['Accept' => 'application/json'];
            if ($this->config->authMethod === 'client_secret_post') {
                $form['client_secret'] = $this->config->secret();
            } elseif ($this->config->authMethod === 'client_secret_basic') {
                $headers['Authorization'] = 'Basic '.base64_encode(urlencode($transaction->clientId).':'.urlencode($this->config->secret() ?? ''));
            }
            $result = $this->http->request('POST', $transaction->provider->tokenEndpoint, ['headers' => $headers, 'form_params' => $form]);
            if ($result->mediaType !== 'application/json') {
                throw new TokenExchangeException('token_exchange_failure');
            }
            $response = $result->data();
            foreach (['access_token', 'id_token'] as $field) {
                if (! is_string($response[$field] ?? null) || $response[$field] === '') {
                    throw new TokenExchangeException('token_exchange_failure');
                }
            }
            if (isset($response['error']) || ! is_string($response['token_type'] ?? null)
                || strcasecmp($response['token_type'], 'Bearer') !== 0
                || ! is_int($response['expires_in'] ?? null) || $response['expires_in'] <= 0) {
                throw new TokenExchangeException('token_exchange_failure');
            }
            $scopes = $transaction->scopes;
            if (isset($response['scope'])) {
                if (! is_string($response['scope'])) {
                    throw new TokenExchangeException('token_exchange_failure');
                }
                $scopes = array_values(array_filter(explode(' ', $response['scope']), static fn ($s) => $s !== ''));
                if (array_diff($scopes, $transaction->scopes) !== []) {
                    throw new TokenExchangeException('token_exchange_failure');
                }
            }
            if (! in_array('openid', $scopes, true) || hash_equals($response['access_token'], $response['id_token'])) {
                throw new TokenExchangeException('token_exchange_failure');
            }

            // Refresh tokens are intentionally discarded, never persisted or returned.
            return new TokenResponse($response['id_token'], $response['access_token'], $scopes);
        } catch (\Throwable) {
            throw new TokenExchangeException('token_exchange_failure');
        }
    }
}
