<?php

declare(strict_types=1);

namespace Doxa\Laravel\Credentials;

use Doxa\Laravel\Identity\DoxaIdentity;

final readonly class ManagedLoginResult extends ProtectedValue
{
    public function __construct(private DoxaIdentity $identity, private CredentialContext $context) {}

    public function identity(): DoxaIdentity
    {
        return $this->identity;
    }

    public function credentialContext(): CredentialContext
    {
        return $this->context;
    }
}
