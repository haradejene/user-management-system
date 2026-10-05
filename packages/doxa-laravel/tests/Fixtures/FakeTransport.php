<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Fixtures;

use Doxa\Laravel\Contracts\Transport;
use Doxa\Laravel\Http\JsonResponse;

final class FakeTransport implements Transport
{
    public array $requests = [];

    public array $responses = [];

    public function request(string $method, string $url, array $options = []): JsonResponse
    {
        $this->requests[] = [$method, $url, $options];
        $response = $this->responses[$url] ?? null;
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if (is_callable($response)) {
            $response = $response($options);
        }

        return $response instanceof JsonResponse ? $response : new JsonResponse($response ?? []);
    }

    public function count(string $url): int
    {
        return count(array_filter($this->requests, static fn ($request) => $request[1] === $url));
    }
}
