<?php

declare(strict_types=1);

namespace Doxa\Laravel\Authorization;

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Discovery\Discovery;
use Doxa\Laravel\Discovery\ProviderMetadata;
use Doxa\Laravel\Exceptions\DiscoveryException;
use Doxa\Laravel\Transaction\AuthorizationTransaction;

final class Authorization
{
    /** @var \WeakMap<AuthorizationTransaction, bool>|null */
    private static ?\WeakMap $issued = null;

    public function __construct(private readonly Discovery $discovery) {}

    public static function issued(#[\SensitiveParameter] AuthorizationTransaction $transaction): bool
    {
        return isset(self::$issued[$transaction]);
    }

    public function create(#[\SensitiveParameter] DoxaConfig $config, ProviderMetadata $provider, #[\SensitiveParameter] string $binding): AuthorizationTransaction
    {
        if ($provider != $this->discovery->discover() || $provider->issuer !== $config->issuer) {
            throw new DiscoveryException('invalid_issuer');
        }
        $now = time();

        $transaction = new AuthorizationTransaction($this->random(), $this->random(), $this->random(), $config->issuer,
            $config->clientId, $config->redirectUri, $config->scopes, $now, $now + 600, $binding, $provider);
        self::$issued ??= new \WeakMap;
        self::$issued[$transaction] = true;

        return $transaction;
    }

    public function url(#[\SensitiveParameter] AuthorizationTransaction $transaction): string
    {
        $parameters = [
            'response_type' => 'code', 'client_id' => $transaction->clientId,
            'redirect_uri' => $transaction->redirectUri, 'scope' => implode(' ', $transaction->scopes),
            'state' => $transaction->state, 'nonce' => $transaction->nonce(),
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $transaction->verifier(), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];
        // Reject endpoint query parameters that could shadow protocol parameters.
        $existing = parse_url($transaction->provider->authorizationEndpoint, PHP_URL_QUERY);
        if ($existing !== null && $existing !== '') {
            throw new DiscoveryException('discovery_error');
        }

        return $transaction->provider->authorizationEndpoint.'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    private function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
