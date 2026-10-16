<?php

declare(strict_types=1);

namespace Doxa\Laravel\Contracts;

use Doxa\Laravel\Credentials\ManagedLoginPolicy;
use Illuminate\Http\Request;

/**
 * Trusted host service, explicitly registered by the host. Never construct proof from request IDs.
 * capture: pending_link requires initiator, link, target, deadline from authorized server records.
 * validate: recheck live session/actor/link/target/purpose and account eligibility for the operation.
 * complete: resolve exact issuer+subject mapping and authorized session transition in host storage;
 * return account + session (SHA-256 session ID). Never create a mapping or authenticate a user.
 * A bind operation may cross session regeneration only through this explicit host verification.
 */
interface CredentialHostBinding
{
    /** @return array<string, mixed> */
    public function capture(#[\SensitiveParameter] Request $request, ManagedLoginPolicy $policy): array;

    /** @param array<string, mixed> $metadata */
    public function validate(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] array $metadata, string $operation): void;

    /** @param array<string, mixed> $metadata @return array{account: string, session: string} */
    public function complete(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] array $metadata): array;
}
