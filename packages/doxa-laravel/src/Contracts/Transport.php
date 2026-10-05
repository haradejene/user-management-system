<?php

declare(strict_types=1);

namespace Doxa\Laravel\Contracts;

use Doxa\Laravel\Http\JsonResponse;

interface Transport
{
    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, #[\SensitiveParameter] array $options = []): JsonResponse;
}
