<?php

declare(strict_types=1);

namespace Doxa\Laravel\Discovery;

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Contracts\Transport;
use Doxa\Laravel\Exceptions\DiscoveryException;
use Illuminate\Contracts\Cache\Repository;

final class Discovery
{
    public function __construct(private readonly DoxaConfig $config, private readonly Transport $http, private readonly Repository $cache) {}

    public function discover(): ProviderMetadata
    {
        $key = 'doxa.discovery.'.hash('sha256', $this->config->issuer);
        try {
            $cached = $this->cache->get($key);
            if (is_array($cached) && ($cached['expires'] ?? 0) > time() && is_array($cached['data'] ?? null)) {
                return ProviderMetadata::fromArray($cached['data'], $this->config);
            }
            $response = $this->http->request('GET', rtrim($this->config->issuer, '/').'/.well-known/openid-configuration');
            if ($response->mediaType !== 'application/json') {
                throw new DiscoveryException('discovery_error');
            }
            $metadata = ProviderMetadata::fromArray($response->data(), $this->config);
            $ttl = min($this->config->discoveryTtl, $response->maxAge ?? $this->config->discoveryTtl);
            if ($ttl > 0 && ! $this->cache->put($key, ['expires' => time() + $ttl, 'data' => $response->data()], $ttl)) {
                throw new DiscoveryException('discovery_error');
            }

            return $metadata;
        } catch (DiscoveryException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new DiscoveryException('discovery_error');
        }
    }
}
