<?php

use App\Http\Middleware\AtomicOAuthTokenExchange;
use App\Http\Middleware\ValidateOAuthAuthorization;

return [
    // OAuth authorization uses the existing IAM browser session.
    'guard' => 'web',
    'middleware' => [ValidateOAuthAuthorization::class, AtomicOAuthTokenExchange::class],
    'private_key' => env('PASSPORT_PRIVATE_KEY'),
    'public_key' => env('PASSPORT_PUBLIC_KEY'),
    'connection' => env('PASSPORT_CONNECTION'),
];
