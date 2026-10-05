<?php

declare(strict_types=1);

namespace Doxa\Laravel\Oidc;

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Contracts\Transport;
use Doxa\Laravel\Discovery\ProviderMetadata;
use Doxa\Laravel\Exceptions\JwksException;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository;

final class JwksProvider
{
    public function __construct(private readonly DoxaConfig $config, private readonly Transport $http, private readonly Repository $cache) {}

    public function key(ProviderMetadata $provider, string $kid): Key
    {
        try {
            if ($provider->issuer !== $this->config->issuer || $kid === '' || strlen($kid) > 256) {
                throw new JwksException('invalid_issuer');
            }
            $cacheKey = 'doxa.jwks.'.hash('sha256', $provider->issuer.'\0'.$provider->jwksUri);
            $cached = $this->cache->get($cacheKey);
            $validCache = is_array($cached) && ($cached['expires'] ?? 0) > time() && is_array($cached['data'] ?? null);
            if (! $validCache && ! $this->cache->add($cacheKey.'.fetch', true, min(5, $this->config->jwksTtl))) {
                throw new JwksException('invalid_signature');
            }
            $data = $validCache ? $cached['data'] : $this->fetch($provider, $cacheKey);
            $keys = $this->parse($data) ?? throw new JwksException('invalid_signature');
            if (isset($keys[$kid])) {
                return $keys[$kid];
            }
            // A fresh fetch already counts as this validation's one network refresh.
            if ($validCache && $this->cache->add($cacheKey.'.refresh', true, 30)) {
                $keys = $this->parse($this->fetch($provider, $cacheKey)) ?? throw new JwksException('invalid_signature');
            }
            if (! isset($keys[$kid])) {
                throw new JwksException('invalid_signature');
            }

            return $keys[$kid];
        } catch (JwksException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new JwksException('invalid_signature');
        }
    }

    /** @return array<string, mixed> */
    private function fetch(ProviderMetadata $provider, string $cacheKey): array
    {
        $response = $this->http->request('GET', $provider->jwksUri);
        $data = $response->data();
        // Return from parsing before creating an exception; never persist unusable key sets.
        if ($this->parse($data) === null) {
            throw new JwksException('invalid_signature');
        }
        $ttl = min($this->config->jwksTtl, $response->maxAge ?? $this->config->jwksTtl);
        if ($ttl > 0 && ! $this->cache->put($cacheKey, ['expires' => time() + $ttl, 'data' => $data], $ttl)) {
            throw new JwksException('invalid_signature');
        }

        return $data;
    }

    /** @param array<string, mixed> $data @return array<string, Key>|null */
    private function parse(array $data): ?array
    {
        if (! is_array($data['keys'] ?? null) || ! array_is_list($data['keys']) || count($data['keys']) > 32) {
            return null;
        }
        $keys = [];
        $seen = [];
        foreach ($data['keys'] as $jwk) {
            if (! is_array($jwk) || ! is_string($jwk['kid'] ?? null) || $jwk['kid'] === '' || isset($seen[$jwk['kid']])) {
                return null;
            }
            $seen[$jwk['kid']] = true;
            if (($jwk['kty'] ?? null) !== 'RSA' || (($jwk['alg'] ?? 'RS256') !== 'RS256')
                || (($jwk['use'] ?? 'sig') !== 'sig') || (isset($jwk['key_ops']) && $jwk['key_ops'] !== ['verify'])
                || array_intersect(['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth'], array_keys($jwk)) !== []) {
                continue;
            }
            foreach (['n', 'e'] as $part) {
                if (! is_string($jwk[$part] ?? null) || preg_match('/^[A-Za-z0-9_-]+$/D', $jwk[$part]) !== 1) {
                    return null;
                }
            }
            $key = JWK::parseKey($jwk, 'RS256');
            $details = $key === null ? false : openssl_pkey_get_details($key->getKeyMaterial());
            if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
                continue;
            }
            $keys[$jwk['kid']] = $key;
        }
        if ($keys === []) {
            return null;
        }

        return $keys;
    }
}
