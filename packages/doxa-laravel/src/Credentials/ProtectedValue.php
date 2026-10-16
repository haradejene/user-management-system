<?php

declare(strict_types=1);

namespace Doxa\Laravel\Credentials;

/** No protected value is a browser DTO or PHP session-serializable object. */
abstract readonly class ProtectedValue implements \JsonSerializable
{
    final public function __debugInfo(): array
    {
        return ['credential_context' => '[redacted]'];
    }

    final public function __serialize(): array
    {
        throw new \LogicException('Protected credential serialization is prohibited.');
    }

    final public function jsonSerialize(): mixed
    {
        throw new \LogicException('Protected credential serialization is prohibited.');
    }
}
