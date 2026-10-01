<?php

namespace App\Models;

use Laravel\Passport\AuthCode as PassportAuthCode;

class OAuthAuthCode extends PassportAuthCode
{
    protected $casts = [
        'revoked' => 'bool',
        'expires_at' => 'datetime',
    ];
}
