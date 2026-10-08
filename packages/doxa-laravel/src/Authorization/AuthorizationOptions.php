<?php

declare(strict_types=1);

namespace Doxa\Laravel\Authorization;

use Doxa\Laravel\Exceptions\AuthorizationException;

/** Trusted host options for the outgoing request, never browser/callback input. */
final readonly class AuthorizationOptions
{
    public ?string $prompt;

    public function __construct(#[\SensitiveParameter] ?string $prompt = null)
    {
        if ($prompt !== null && $prompt !== 'login') {
            throw new AuthorizationException('unsupported_authorization_option');
        }
        $this->prompt = $prompt;
    }

    public static function reauthenticate(): self
    {
        return new self('login');
    }
}
