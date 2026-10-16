<?php

declare(strict_types=1);

namespace Doxa\Laravel\Contracts;

/** Internal storage encryption boundary, not a token export API. */
interface CredentialCipher
{
    public function keyId(): string;

    /** @param array<string, mixed> $payload */
    public function seal(#[\SensitiveParameter] array $payload): string;

    /** @return array<string, mixed> */
    public function open(#[\SensitiveParameter] string $ciphertext, string $keyId): array;
}
