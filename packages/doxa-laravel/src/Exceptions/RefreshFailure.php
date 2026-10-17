<?php

declare(strict_types=1);

namespace Doxa\Laravel\Exceptions;

enum RefreshFailure: string
{
    case BeforeDispatch = 'refresh_pre_dispatch_failure';
    case Uncertain = 'refresh_outcome_uncertain';
    case ContractViolation = 'refresh_contract_violation';
    case CredentialInvalid = 'credential_invalid';
    case ClientUnusable = 'client_unusable';
}
