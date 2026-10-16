<?php

declare(strict_types=1);

namespace Doxa\Laravel\Exceptions;

final class CredentialException extends DoxaException
{
    public function __construct(#[\SensitiveParameter] string $category = 'credential_lifecycle_failure')
    {
        // Host adapters cannot accidentally turn a provider/SQL description into a public category.
        $allowed = ['credential_configuration_error', 'credential_store_unavailable', 'credential_store_integrity_failure',
            'credential_lifecycle_failure', 'credential_binding_failure', 'credential_callback_finalization_failure',
            'credential_policy_mismatch', 'incompatible_context', 'authorization_error', 'configuration_error',
            'discovery_error', 'unsupported_provider_capability', 'unsupported_authorization_option', 'invalid_state',
            'invalid_nonce', 'invalid_pkce_transaction', 'token_exchange_failure', 'invalid_id_token', 'invalid_issuer',
            'invalid_audience', 'invalid_signature', 'expired_id_token', 'identity_extraction_failure', 'userinfo_failure',
            'transaction_expired', 'transaction_replay', 'transaction_storage_failure', 'user_denied_authorization', 'http_failure'];
        parent::__construct(in_array($category, $allowed, true) ? $category : 'credential_lifecycle_failure');
    }
}
