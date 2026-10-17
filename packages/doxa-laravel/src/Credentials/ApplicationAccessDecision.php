<?php

declare(strict_types=1);

namespace Doxa\Laravel\Credentials;

/** A point-in-time authoritative answer; never continuing authorization. */
final readonly class ApplicationAccessDecision
{
    private function __construct(public bool $effective) {}

    /** Internal result factory; a decision is not a credential or dispatch authority. */
    public static function confirmed(): self
    {
        return new self(true);
    }
}
