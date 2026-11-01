<?php

declare(strict_types=1);

namespace Doxa\Laravel\Contracts;

use Doxa\Laravel\Identity\DoxaIdentity;
use Illuminate\Contracts\Auth\Authenticatable;

/** Host policy invoked by DoxaClient::resolveAccount only after OIDC and fresh application access.
 * Resolve exact issuer/subject first. Unknown identities may be denied or provisioned under explicit
 * host policy. Never attach by email, elevate roles, or perform a local session transition here.
 * All created local data and the unique mapping must share one host database transaction.
 */
interface AccountResolver
{
    public function resolve(#[\SensitiveParameter] DoxaIdentity $identity): ?Authenticatable;
}
