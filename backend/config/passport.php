<?php

return [
    // OAuth authorization uses the existing IAM browser session.
    'guard' => 'web',
    'middleware' => [],
    'private_key' => env('PASSPORT_PRIVATE_KEY'),
    'public_key' => env('PASSPORT_PUBLIC_KEY'),
    'connection' => env('PASSPORT_CONNECTION'),
];
