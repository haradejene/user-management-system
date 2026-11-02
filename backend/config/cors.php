<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'oauth/account-choice/*', 'oauth/select-account'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_unique(array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        'http://localhost:3000',
        'http://localhost:3001',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:3001',
    ]))),
    'allowed_origins_patterns' => [
        '#^https?://(localhost|127\.0\.0\.1):(3000|3001)$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
