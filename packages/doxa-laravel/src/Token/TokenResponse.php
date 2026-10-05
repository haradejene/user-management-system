<?php

declare(strict_types=1);

namespace Doxa\Laravel\Token;

final readonly class TokenResponse
{
    /** @param list<string> $scopes */
    public function __construct(private string $idToken, private string $accessToken, public array $scopes) {}

    public function idToken(): string
    {
        return $this->idToken;
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function __debugInfo(): array
    {
        return ['tokens' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Token serialization is prohibited.');
    }
}
