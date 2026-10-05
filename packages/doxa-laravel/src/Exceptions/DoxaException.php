<?php

declare(strict_types=1);

namespace Doxa\Laravel\Exceptions;

class DoxaException extends \RuntimeException
{
    public function __construct(public readonly string $category = 'authorization_error')
    {
        parent::__construct('Doxa authentication failed ('.$category.').');
    }
}
