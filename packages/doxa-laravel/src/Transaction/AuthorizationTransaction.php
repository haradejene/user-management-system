<?php

declare(strict_types=1);

namespace Doxa\Laravel\Transaction;

use Doxa\Laravel\Discovery\ProviderMetadata;

final readonly class AuthorizationTransaction
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $state,
        private string $nonce,
        private string $verifier,
        public string $issuer,
        public string $clientId,
        public string $redirectUri,
        public array $scopes,
        public int $createdAt,
        public int $expiresAt,
        private string $browserBinding,
        public ProviderMetadata $provider,
        public string $status = 'pending',
    ) {}

    public function nonce(): string
    {
        return $this->nonce;
    }

    public function verifier(): string
    {
        return $this->verifier;
    }

    public function browserBinding(): string
    {
        return $this->browserBinding;
    }

    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'secrets' => '[redacted]'];
    }
}
