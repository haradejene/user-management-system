<?php

declare(strict_types=1);

namespace Doxa\Laravel\Credentials;

/** An attempt reference is not ownership proof or permission to repeat a dispatch. */
final readonly class RefreshAttempt extends ProtectedValue
{
    public function __construct(public string $contextId, public string $id, public int $version, public string $phase) {}
}
