<?php

declare(strict_types=1);

namespace Doxa\Laravel\Credentials;

use Doxa\Laravel\Exceptions\CredentialException;

final readonly class CredentialContext extends ProtectedValue
{
    private function __construct(private string $reference) {}

    /** An untrusted reference is never ownership proof. Store only in protected server records. */
    public static function fromServerReference(#[\SensitiveParameter] string $reference): self
    {
        if (preg_match('/^[a-f0-9]{64}(?:\.[a-f0-9]{64})?$/D', $reference) !== 1) {
            throw new CredentialException('incompatible_context');
        }

        return new self($reference);
    }

    /** Internal storage key; a completed reference additionally carries a release proof. */
    public function recordId(): string
    {
        return explode('.', $this->reference, 2)[0];
    }

    /** Internal comparison; neither the stored hash nor an ID manufactures completion. */
    public function confirms(array $record): bool
    {
        $parts = explode('.', $this->reference, 2);

        return isset($parts[1], $record['binding']['proof_hash'])
            && hash_equals($record['binding']['proof_hash'], hash('sha256', $parts[1]));
    }

    /** Opaque reference only, never a token. Every operation independently checks host ownership. */
    public function serverReference(): string
    {
        return $this->reference;
    }
}
