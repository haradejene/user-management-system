<?php

declare(strict_types=1);

namespace Doxa\Laravel\Contracts;

use Illuminate\Http\Request;

/**
 * Optional trusted host transaction adapter. Never implement these checks using request IDs/flags.
 * The host owns its database transactions; no SDK database transaction spans these calls or IAM.
 */
interface PendingLinkHostBinding extends CredentialHostBinding
{
    /**
     * Atomically claim the protected linking transaction for this exact SDK attempt, only after
     * verifying explicit confirmation, live initiator/session, immutable target, eligibility,
     * conflicts, issuer/client/subject and deadline. Confirm durable claim before returning.
     * The claim must also durably schedule reconciliation after worker death. A failed/cancelled
     * attempt is consumed, never reopened. Repeated/foreign attempts must not change a winner.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function claimPendingLink(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] array $metadata, string $attempt): void;

    /**
     * After the SDK's fresh access check, recheck conflicts/eligibility and atomically commit the
     * exact mapping and completed linking state for this attempt. Never create/login a local user.
     * Return only after local COMMIT. Session regeneration requires explicit trusted verification.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{account: string, session: string}
     */
    public function commitPendingLink(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] array $metadata, string $attempt): array;

    /**
     * Read-only recovery: prove that this exact attempt's local mapping/state actually committed.
     * Recheck live ownership/confirmation and return its immutable account/session association.
     * Fail closed on missing/uncertain commit; never retry mapping creation here.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{account: string, session: string}
     */
    public function verifyPendingLinkCommit(#[\SensitiveParameter] Request $request, #[\SensitiveParameter] array $metadata, string $attempt): array;

    /**
     * Idempotently consume/cancel ONLY this attempt and roll back/compensate its own mapping.
     * Never remove a mapping belonging to another/newer attempt. If unavailable, the durable
     * claim's recovery job must finish this work before the host treats the link as usable.
     * A crash before the host safely stores the returned completed reference requires this same
     * abort/reconciliation, context destruction and fresh authorization, not proof reconstruction.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function abortPendingLink(#[\SensitiveParameter] array $metadata, string $attempt): void;
}
