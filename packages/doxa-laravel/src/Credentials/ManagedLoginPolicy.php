<?php

declare(strict_types=1);

namespace Doxa\Laravel\Credentials;

use Doxa\Laravel\Exceptions\CredentialException;

final readonly class ManagedLoginPolicy extends ProtectedValue
{
    private function __construct(public string $purpose, public ?string $linkReference) {}

    public static function ordinary(): self
    {
        return new self('ordinary', null);
    }

    /** Reference is resolved and authorized by the configured host adapter, not taken as proof. */
    public static function pendingLink(#[\SensitiveParameter] string $linkReference): self
    {
        if ($linkReference === '' || strlen($linkReference) > 255) {
            throw new CredentialException('incompatible_context');
        }

        return new self('pending_link', $linkReference);
    }
}
