<?php

declare(strict_types=1);

namespace Doxa\Laravel\Oidc;

use Firebase\JWT\JWT;

/** Isolated library policy: host JWT static settings cannot weaken SDK validation. */
final class StrictJwt extends JWT
{
    public static $leeway = 0;

    public static $timestamp = null;

    public static $supported_algs = ['RS256' => ['openssl', 'SHA256']];
}
