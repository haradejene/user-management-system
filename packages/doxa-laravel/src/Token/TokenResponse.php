<?php

declare(strict_types=1);

namespace Doxa\Laravel\Token;

use Doxa\Laravel\Contracts\CredentialStore;
use Doxa\Laravel\Exceptions\CredentialException;

final readonly class TokenResponse
{
    /** @param list<string> $scopes */
    public function __construct(private string $idToken, private string $accessToken, public array $scopes, private ?string $refreshToken = null, private ?int $accessExpiresAt = null) {}

    /** Internal one-way handoff. No refresh credential getter or raw response is exposed.
     * @param  array<string, mixed>  $metadata
     */
    public function persist(#[\SensitiveParameter] CredentialStore $store, #[\SensitiveParameter] array $metadata): void
    {
        if ($this->accessExpiresAt === null || $this->accessExpiresAt <= time() + 60) {
            throw new CredentialException('credential_lifecycle_failure');
        }
        $store->stage([...$metadata, 'access_token' => $this->accessToken, 'refresh_token' => $this->refreshToken,
            'access_expires_at' => $this->accessExpiresAt, 'refresh_expires_at' => null, 'token_type' => 'Bearer']);
    }

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
