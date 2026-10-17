<?php

declare(strict_types=1);

namespace Doxa\Laravel\Exceptions;

/** No provider text, cause, request, response or retry instruction is retained. */
final class RefreshResponseException extends DoxaException
{
    public function __construct(public readonly RefreshFailure $failure)
    {
        parent::__construct($failure->value);
    }
}
