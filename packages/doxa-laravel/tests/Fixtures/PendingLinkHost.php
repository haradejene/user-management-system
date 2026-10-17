<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Fixtures;

use Doxa\Laravel\Contracts\PendingLinkHostBinding;
use Doxa\Laravel\Credentials\ManagedLoginPolicy;
use Doxa\Laravel\Exceptions\CredentialException;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;

/** Test-only durable host confirmation/mapping ledger, not an HRM implementation. */
final class PendingLinkHost implements PendingLinkHostBinding
{
    public bool $loseCommitAck = false;

    public bool $cannotVerify = false;

    public ?\Closure $beforeCommit = null;

    public ?\Closure $afterCommit = null;

    public function __construct(public Connection $db, public string $table, public CredentialHost $inner, bool $create = true)
    {
        if (! $create) {
            return;
        }
        $db->getSchemaBuilder()->create($table, function (Blueprint $row): void {
            $row->string('link')->primary();
            $row->string('attempt')->nullable();
            $row->string('state');
            $row->boolean('confirmed');
            $row->text('mapping')->nullable();
        });
        $db->table($table)->insert(['link' => 'link-1', 'attempt' => null, 'state' => 'pending', 'confirmed' => true]);
    }

    public function capture(Request $request, ManagedLoginPolicy $policy): array
    {
        return $this->inner->capture($request, $policy);
    }

    public function validate(Request $request, array $metadata, string $operation): void
    {
        $this->inner->validate($request, $metadata, $operation);
        if ($this->inner->account !== $this->inner->target) {
            throw new CredentialException('incompatible_context');
        }
        if (($metadata['purpose'] ?? null) === 'pending_link') {
            $row = $this->db->table($this->table)->first();
            if ($row->state === 'aborted' || ($row->attempt !== null && $row->attempt !== ($metadata['completion']['attempt'] ?? null))) {
                throw new CredentialException('incompatible_context');
            }
        }
    }

    public function complete(Request $request, array $metadata): array
    {
        return $this->inner->complete($request, $metadata);
    }

    public function claimPendingLink(Request $request, array $metadata, string $attempt): void
    {
        $this->validate($request, $metadata, 'complete_pending_link');
        if ($this->db->table($this->table)->where('link', $metadata['policy']['host']['link'])
            ->where('confirmed', true)->where('state', 'pending')->whereNull('attempt')
            ->update(['attempt' => $attempt, 'state' => 'claimed']) !== 1) {
            throw new CredentialException('incompatible_context');
        }
    }

    public function commitPendingLink(Request $request, array $metadata, string $attempt): array
    {
        if ($this->beforeCommit !== null) {
            ($this->beforeCommit)();
        }
        $this->validate($request, $metadata, 'complete_pending_link');
        $mapping = json_encode([$metadata['issuer'], $metadata['subject'], $metadata['policy']['host']['target']], JSON_THROW_ON_ERROR);
        if ($this->db->table($this->table)->where('attempt', $attempt)->where('state', 'claimed')
            ->update(['state' => 'committed', 'mapping' => $mapping]) !== 1) {
            throw new CredentialException('incompatible_context');
        }
        if ($this->afterCommit !== null) {
            ($this->afterCommit)();
        }
        if ($this->loseCommitAck) {
            throw new \RuntimeException('Lost host COMMIT acknowledgement');
        }

        return $this->verifyPendingLinkCommit($request, $metadata, $attempt);
    }

    public function verifyPendingLinkCommit(Request $request, array $metadata, string $attempt): array
    {
        if ($this->cannotVerify) {
            throw new \RuntimeException('Host unavailable');
        }
        $this->validate($request, $metadata, 'complete_pending_link');
        $row = $this->db->table($this->table)->where('attempt', $attempt)->where('state', 'committed')->first();
        if ($row === null || json_decode($row->mapping, true) !== [$metadata['issuer'], $metadata['subject'], $metadata['policy']['host']['target']]) {
            throw new CredentialException('incompatible_context');
        }

        return $this->inner->complete($request, $metadata);
    }

    public function abortPendingLink(array $metadata, string $attempt): void
    {
        $this->db->table($this->table)->where('link', $metadata['policy']['host']['link'])
            ->where(function ($query) use ($attempt): void {
                $query->where('attempt', $attempt)->orWhereNull('attempt');
            })->update(['state' => 'aborted', 'attempt' => $attempt, 'mapping' => null]);
    }
}
