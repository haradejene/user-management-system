<?php

declare(strict_types=1);

namespace Doxa\Laravel\Http;

final readonly class JsonResponse
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data, public ?int $maxAge = null, public string $mediaType = 'application/json') {}

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function __debugInfo(): array
    {
        return ['data' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Protocol response serialization is prohibited.');
    }
}
