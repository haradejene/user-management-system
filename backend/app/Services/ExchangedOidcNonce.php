<?php

namespace App\Services;

class ExchangedOidcNonce
{
    // Internal context for this successful Passport token request only.
    // Neither identity nor nonce is taken from token-request parameters.
    public function nonce(): ?string
    {
        return app('request')->attributes->get('oidc_exchanged_nonce');
    }

    public function authorizationCodeId(): ?string
    {
        return app('request')->attributes->get('oidc_exchanged_code_id');
    }
}
