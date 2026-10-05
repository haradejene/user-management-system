<?php

declare(strict_types=1);

namespace Doxa\Laravel\Http;

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Contracts\Transport;
use Doxa\Laravel\Exceptions\DoxaException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

final class GuzzleTransport implements Transport
{
    private readonly ClientInterface $client;

    public function __construct(private readonly DoxaConfig $config, ?ClientInterface $client = null)
    {
        // Dedicated client: no host HTTP events, middleware, logging or retries.
        $this->client = $client ?? new Client;
    }

    public function request(string $method, string $url, #[\SensitiveParameter] array $options = []): JsonResponse
    {
        try {
            DoxaConfig::url($url);
            $deadline = microtime(true) + $this->config->timeout;
            $response = $this->client->request($method, $url, [
                ...$options,
                'verify' => true,
                'timeout' => $this->config->timeout,
                'connect_timeout' => $this->config->timeout,
                'read_timeout' => $this->config->timeout,
                'allow_redirects' => false,
                'http_errors' => false,
                'stream' => true,
                'debug' => false,
            ]);
            $type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
            if ($response->getStatusCode() !== 200 || ! in_array($type, ['application/json', 'application/jwk-set+json'], true)) {
                $response->getBody()->close();
                throw new \RuntimeException;
            }
            $body = $response->getBody();
            $json = '';
            try {
                while (! $body->eof()) {
                    if (microtime(true) >= $deadline) {
                        throw new \RuntimeException;
                    }
                    $json .= $body->read(8192);
                    if (strlen($json) > 262144) {
                        throw new \RuntimeException;
                    }
                }
            } finally {
                $body->close();
            }
            $object = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
            if (! $object instanceof \stdClass) {
                throw new \RuntimeException;
            }
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            $age = null;
            $control = strtolower($response->getHeaderLine('Cache-Control'));
            if (str_contains($control, 'no-store') || str_contains($control, 'no-cache')) {
                $age = 0;
            } elseif (preg_match('/(?:^|[,\s])max-age=(\d+)/', $control, $match)) {
                $age = max(0, (int) $match[1] - (int) $response->getHeaderLine('Age'));
            }

            return new JsonResponse($data, $age, $type);
        } catch (\Throwable) {
            // Never chain transport exceptions containing request/response objects.
            throw new DoxaException('http_failure');
        }
    }
}
