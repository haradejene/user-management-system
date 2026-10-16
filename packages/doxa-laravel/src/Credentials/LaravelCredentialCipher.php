<?php

declare(strict_types=1);

namespace Doxa\Laravel\Credentials;

use Doxa\Laravel\Contracts\CredentialCipher;
use Doxa\Laravel\Exceptions\CredentialException;
use Illuminate\Encryption\Encrypter;

final class LaravelCredentialCipher implements CredentialCipher
{
    private readonly Encrypter $encrypter;

    public function __construct(#[\SensitiveParameter] string $key, private readonly string $identifier)
    {
        try {
            $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
            if (! is_string($decoded) || strlen($decoded) !== 32 || preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $identifier) !== 1) {
                throw new \RuntimeException;
            }
            $this->encrypter = new Encrypter($decoded, 'AES-256-CBC');
        } catch (\Throwable) {
            throw new CredentialException('credential_configuration_error');
        }
    }

    public function keyId(): string
    {
        return $this->identifier;
    }

    public function seal(#[\SensitiveParameter] array $payload): string
    {
        try {
            return $this->encrypter->encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            throw new CredentialException('credential_store_integrity_failure');
        }
    }

    public function open(#[\SensitiveParameter] string $ciphertext, string $keyId): array
    {
        try {
            if ($keyId !== $this->identifier) {
                throw new \RuntimeException;
            }
            $data = json_decode($this->encrypter->decryptString($ciphertext), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new \RuntimeException;
            }

            return $data;
        } catch (\Throwable) {
            throw new CredentialException('credential_store_integrity_failure');
        }
    }

    public function __debugInfo(): array
    {
        return ['cipher' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Credential cipher serialization is prohibited.');
    }
}
