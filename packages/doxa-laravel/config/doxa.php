<?php

return [
    'issuer' => env('DOXA_ISSUER'),
    'client_id' => env('DOXA_CLIENT_ID'),
    'client_secret' => env('DOXA_CLIENT_SECRET'),
    'redirect_uri' => env('DOXA_REDIRECT_URI'),
    'scopes' => ['openid'],
    'pkce_required' => true,
    'timeout' => 10,
    'discovery_cache_ttl' => 300,
    'jwks_cache_ttl' => 300,
    'client_auth_method' => null, // none without a secret; Basic with a secret.
    'cache_store' => null, // Host default; must support persistent atomic add.
    'userinfo_enabled' => false,
    'credentials' => [
        'enabled' => false,
        'connection' => null,
        'table' => 'doxa_credential_contexts',
        'key' => env('DOXA_CREDENTIAL_KEY'), // Explicit approved key, never APP_KEY fallback.
        'key_id' => env('DOXA_CREDENTIAL_KEY_ID'),
        'lifetime_seconds' => 3600,
        'refresh_margin_seconds' => 60, // Integer 0..60; never extends actual expiry.
        'unbound_seconds' => 300,
        'tombstone_seconds' => 3600,
    ],
];
