<?php

declare(strict_types=1);

namespace Doxa\Laravel\Exceptions;

final class ApplicationAccessException extends DoxaException
{
    public function __construct(#[\SensitiveParameter] string $category)
    {
        parent::__construct(in_array($category, ['credential_invalid', 'client_unusable',
            'reauthorization_required', 'credential_busy', 'refresh_outcome_uncertain',
            'refresh_contract_violation', 'access_denied_or_insufficient_scope',
            'incompatible_context', 'transient_service_failure', 'protocol_failure',
            'credential_store_unavailable', 'credential_store_integrity_failure',
            'credential_lifecycle_failure', 'unsupported_provider_capability'], true)
            ? $category : 'credential_lifecycle_failure');
    }
}
