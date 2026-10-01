<?php

return [
    // A stable issuer, independent of request Host headers. Defaults to APP_URL.
    'issuer' => env('OIDC_ISSUER'),
];
