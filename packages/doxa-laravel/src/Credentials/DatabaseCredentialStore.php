<?php

declare(strict_types=1);

namespace Doxa\Laravel\Credentials;

use Doxa\Laravel\Contracts\CredentialCipher;
use Doxa\Laravel\Contracts\RefreshCredentialStore;
use Doxa\Laravel\Exceptions\CredentialException;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;

final class DatabaseCredentialStore implements RefreshCredentialStore
{
    use ManagedApplicationAccess;
    use PendingLinkCompletion;

    /** @var \WeakMap<self, \Closure>|null Internal deterministic-handler injection; never part of a diagnostic object graph. */
    private static ?\WeakMap $operationHandlers = null;

    private readonly Connection $db;

    public function __construct(
        #[\SensitiveParameter] ConnectionInterface $db,
        #[\SensitiveParameter] private readonly CredentialCipher $cipher,
        private readonly string $table = 'doxa_credential_contexts',
        private readonly int $tombstoneSeconds = 3600,
        #[\SensitiveParameter] ?\Closure $operationHandler = null,
    ) {
        if (! $db instanceof Connection || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $table) !== 1 || $tombstoneSeconds < 60 || $tombstoneSeconds > 604800) {
            throw new CredentialException('credential_configuration_error');
        }
        if ($db->getDriverName() === 'sqlite' && ($db->getDatabaseName() === ':memory:'
            || str_contains($db->getDatabaseName(), 'mode=memory') || str_contains($db->getDatabaseName(), 'file::memory:'))) {
            throw new CredentialException('credential_configuration_error');
        }
        if ($operationHandler !== null) {
            self::$operationHandlers ??= new \WeakMap;
            self::$operationHandlers[$this] = $operationHandler;
        }
        // Isolated instrumentation; never emit credential SQL bindings through Laravel events.
        $this->db = clone $db;
        $this->db->unsetEventDispatcher();
        $this->db->disableQueryLog();
        $this->db->flushQueryLog();
    }

    public function ready(): void
    {
        try {
            $this->autocommit();
            if (! $this->db->getSchemaBuilder()->hasColumns($this->table, ['id', 'provenance', 'status', 'version', 'expires_at', 'purge_at', 'key_id', 'payload'])) {
                throw new \RuntimeException;
            }
            CredentialSchema::assertReady($this->db, $this->table);
            $this->db->table($this->table)->useWritePdo()->limit(1)->get();
            if ($this->cipher->open($this->cipher->seal(['probe' => true]), $this->cipher->keyId()) !== ['probe' => true]) {
                throw new \RuntimeException;
            }
        } catch (CredentialException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new CredentialException('credential_store_unavailable');
        }
    }

    public function stage(#[\SensitiveParameter] array $payload): void
    {
        try {
            $this->autocommit();
            if (($payload['status'] ?? null) !== 'staged' || ($payload['version'] ?? null) !== 1
                || ! is_string($payload['access_token'] ?? null) || $payload['access_token'] === ''
                || ! is_int($payload['expires_at'] ?? null) || $payload['expires_at'] <= time()) {
                throw new CredentialException('incompatible_context');
            }
            // A unique provenance constraint prevents creation twice for one callback.
            if (! $this->db->table($this->table)->insert($this->columns($payload))) {
                throw new \RuntimeException;
            }
        } catch (CredentialException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new CredentialException('credential_store_unavailable');
        }
    }

    public function metadata(#[\SensitiveParameter] string $id): array
    {
        $payload = $this->read($id);
        unset($payload['access_token'], $payload['refresh_token']);

        return $payload;
    }

    public function transition(#[\SensitiveParameter] string $id, int $version, string $from, string $to, #[\SensitiveParameter] array $association = []): void
    {
        $payload = $this->read($id);
        $allowed = ($from === 'staged' && $to === (($payload['purpose'] ?? null) === 'pending_link' ? 'pending_link' : 'awaiting_binding'))
            || ($from === 'awaiting_binding' && $to === 'binding' && ($payload['purpose'] ?? null) === 'ordinary')
            || ($from === 'binding' && $to === 'active' && ($payload['purpose'] ?? null) === 'ordinary'
                && ($payload['binding']['attempt'] ?? null) === ($association['attempt'] ?? null)
                && ($payload['binding']['proof_hash'] ?? null) === ($association['proof_hash'] ?? null));
        if (! $allowed || $payload['status'] !== $from || $payload['version'] !== $version || $payload['expires_at'] <= time()) {
            throw new CredentialException('credential_lifecycle_failure');
        }
        if ($to === 'binding') {
            if (preg_match('/^[a-f0-9]{64}$/D', $association['attempt'] ?? '') !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $association['proof_hash'] ?? '') !== 1) {
                throw new CredentialException('incompatible_context');
            }
            $payload['binding'] = ['attempt' => $association['attempt'], 'proof_hash' => $association['proof_hash']];
        }
        if ($to === 'active') {
            if (preg_match('/^[a-f0-9]{64}$/D', $association['attempt'] ?? '') !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $association['proof_hash'] ?? '') !== 1
                || ! is_string($association['account'] ?? null) || $association['account'] === ''
                || ! is_string($association['session'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $association['session']) !== 1) {
                throw new CredentialException('incompatible_context');
            }
            $payload['binding'] = ['attempt' => $association['attempt'], 'proof_hash' => $association['proof_hash']];
            $payload['owner'] = ['account' => $association['account'], 'session' => $association['session']];
            $payload['expires_at'] = $payload['absolute_expires_at'];
        }
        $payload['status'] = $to;
        $payload['version']++;
        $this->replace($payload, $version, $from, true);
    }

    public function delete(#[\SensitiveParameter] string $id, int $version, string $terminal = 'deleted'): void
    {
        $payload = $this->read($id);
        if (in_array($payload['status'], ['deleted', 'expired', 'unusable'], true)) {
            return;
        }
        if ($payload['version'] !== $version || ! in_array($terminal, ['deleted', 'expired', 'unusable'], true)) {
            throw new CredentialException('credential_lifecycle_failure');
        }
        $from = $payload['status'];
        unset($payload['access_token'], $payload['refresh_token']);
        $payload['status'] = $terminal;
        $payload['version']++;
        $payload['purge_at'] = time() + $this->tombstoneSeconds;
        $this->replace($payload, $version, $from);
    }

    public function abandonBinding(#[\SensitiveParameter] string $id, int $version, #[\SensitiveParameter] string $attempt): void
    {
        $payload = $this->read($id);
        if (! in_array($payload['status'], ['binding', 'active'], true) || $payload['version'] !== $version
            || ($payload['binding']['attempt'] ?? null) !== $attempt) {
            return;
        }
        $this->delete($id, $version, 'unusable');
    }

    public function claimRefresh(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected, int $seconds): RefreshAttempt
    {
        return $this->claimForOperation($context, $expected, $seconds, false);
    }

    private function claimForOperation(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected, int $seconds, bool $pending, int $margin = 60): RefreshAttempt
    {
        $payload = $this->refreshOwner($context, $expected, $pending);
        $liveState = $pending ? 'pending_link' : 'active';
        if ($seconds < 1 || $seconds > 60 || $payload['status'] !== $liveState || $payload['version'] !== $expected['version']
            || $payload['access_expires_at'] > time() + $margin || ! is_string($payload['refresh_token'] ?? null)
            || $payload['refresh_token'] === '' || ($payload['refresh_expires_at'] !== null && $payload['refresh_expires_at'] <= time() + $margin)) {
            throw new CredentialException('credential_lifecycle_failure');
        }
        $payload['refresh'] = ['id' => bin2hex(random_bytes(32)), 'phase' => 'claimed',
            'context_expires_at' => $payload['expires_at'], 'return_state' => $liveState];
        $payload['status'] = 'refreshing';
        $payload['version']++;
        // Reuse the indexed lifecycle deadline; the protected original limit is never extended.
        $payload['expires_at'] = min($payload['expires_at'], time() + $seconds);
        $this->replace($payload, $expected['version'], $liveState, true);

        return new RefreshAttempt($payload['id'], $payload['refresh']['id'], $payload['version'], 'claimed');
    }

    public function dispatchRefresh(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected, RefreshAttempt $attempt): RefreshAttempt
    {
        return $this->dispatchForOperation($context, $expected, $attempt, false);
    }

    private function dispatchForOperation(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected, RefreshAttempt $attempt, bool $pending): RefreshAttempt
    {
        $payload = $this->refreshAttempt($context, $expected, $attempt, 'claimed', $pending);
        $payload['refresh']['phase'] = 'dispatched';
        $payload['version']++;
        $this->replace($payload, $attempt->version, 'refreshing', true);

        // Never acknowledge this transition twice: a lost acknowledgement is not redispatch permission.
        return new RefreshAttempt($payload['id'], $attempt->id, $payload['version'], 'dispatched');
    }

    public function releaseRefresh(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected, RefreshAttempt $attempt): void
    {
        $payload = $this->refreshAttempt($context, $expected, $attempt, 'claimed');
        $this->releaseClaim($payload);
    }

    public function failRefresh(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected, RefreshAttempt $attempt): void
    {
        $payload = $this->refreshAttempt($context, $expected, $attempt, 'dispatched');
        $payload['reason'] = 'refresh_outcome_uncertain';
        $this->retireRefresh($payload);
    }

    public function completeRefresh(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected, RefreshAttempt $attempt, #[\SensitiveParameter] array $replacement): void
    {
        $this->completeForOperation($context, $expected, $attempt, $replacement, false);
    }

    private function completeForOperation(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected, RefreshAttempt $attempt, #[\SensitiveParameter] array $replacement, bool $pending, int $margin = 60): void
    {
        $payload = $this->refreshOwner($context, $expected, $pending);
        $fields = ['access_token', 'refresh_token', 'access_expires_at', 'refresh_expires_at', 'scopes'];
        // Reconcile only the exact already-committed response after a lost store acknowledgement.
        // This is a local read, never another provider exchange.
        if ($attempt->contextId === $payload['id'] && $attempt->phase === 'dispatched' && $payload['status'] === ($pending ? 'pending_link' : 'active')
            && $payload['version'] === $attempt->version + 1 && ($payload['refresh']['id'] ?? null) === $attempt->id
            && ($payload['refresh']['phase'] ?? null) === 'completed'
            && count($replacement) === count($fields)
            && $this->sameReplacement($payload, $replacement, $fields)) {
            return;
        }
        $payload = $this->refreshAttempt($context, $expected, $attempt, 'dispatched', $pending);
        if (array_diff(array_keys($replacement), $fields) !== [] || count($replacement) !== count($fields)
            || ! is_string($replacement['access_token'] ?? null) || $replacement['access_token'] === ''
            || ! is_string($replacement['refresh_token'] ?? null) || $replacement['refresh_token'] === ''
            || hash_equals($payload['refresh_token'], $replacement['refresh_token'])
            || ! is_int($replacement['access_expires_at'] ?? null) || $replacement['access_expires_at'] <= time() + $margin
            || $replacement['access_expires_at'] > time() + 2147483647
            || ! array_key_exists('refresh_expires_at', $replacement)
            || ($replacement['refresh_expires_at'] !== null && (! is_int($replacement['refresh_expires_at']) || $replacement['refresh_expires_at'] <= time() + $margin))
            || ! is_array($replacement['scopes'] ?? null) || ! array_is_list($replacement['scopes'])
            || array_filter($replacement['scopes'], static fn ($scope) => ! is_string($scope) || $scope === '') !== []
            || array_diff($replacement['scopes'], $payload['scopes']) !== []
            || array_diff($replacement['scopes'], $payload['scope_ceiling']) !== []) {
            $payload['reason'] = 'refresh_contract_violation';
            $this->retireRefresh($payload);
            throw new CredentialException('credential_lifecycle_failure');
        }
        $payload = [...$payload, ...$replacement];
        $payload['status'] = $pending ? 'pending_link' : 'active';
        $payload['expires_at'] = $payload['refresh']['context_expires_at'];
        $payload['refresh']['phase'] = 'completed';
        $payload['version']++;
        $this->replace($payload, $attempt->version, 'refreshing', true);
    }

    /** @return array<string, mixed> */
    private function refreshOwner(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected, bool $pending = false): array
    {
        $payload = $this->read($context->recordId());
        // Even read-only completion reconciliation must observe acknowledged durable state.
        $this->autocommit();
        foreach (['id', 'issuer', 'client', 'subject', 'purpose', 'owner', 'policy', 'binding', 'grant', 'scope_ceiling', 'absolute_expires_at'] as $field) {
            if ((! array_key_exists($field, $expected) && ! ($pending && $field === 'binding'))
                || ($payload[$field] ?? null) !== ($expected[$field] ?? null)) {
                throw new CredentialException('incompatible_context');
            }
        }
        if ((! $pending && ($payload['purpose'] !== 'ordinary' || $payload['owner'] === null || ! $context->confirms($payload)))
            || ($pending && ($payload['purpose'] !== 'pending_link' || $payload['owner'] !== null
                || ($payload['policy']['host']['deadline'] ?? 0) <= time()))
            || $payload['grant'] !== 'authorization_code' || $payload['absolute_expires_at'] <= time()
            || $payload['expires_at'] <= time()) {
            throw new CredentialException('incompatible_context');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $replacement @param list<string> $fields */
    private function sameReplacement(#[\SensitiveParameter] array $payload, #[\SensitiveParameter] array $replacement, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $replacement) || $payload[$field] !== $replacement[$field]) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function refreshAttempt(#[\SensitiveParameter] CredentialContext $context, #[\SensitiveParameter] array $expected, RefreshAttempt $attempt, string $phase, bool $pending = false): array
    {
        $payload = $this->refreshOwner($context, $expected, $pending);
        if ($attempt->contextId !== $payload['id'] || $attempt->phase !== $phase || $payload['status'] !== 'refreshing'
            || $payload['version'] !== $attempt->version || ($payload['refresh']['id'] ?? null) !== $attempt->id
            || ($payload['refresh']['phase'] ?? null) !== $phase) {
            throw new CredentialException('credential_lifecycle_failure');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function releaseClaim(#[\SensitiveParameter] array $payload): void
    {
        $version = $payload['version'];
        $payload['status'] = $payload['refresh']['return_state'] ?? 'active';
        $payload['expires_at'] = $payload['refresh']['context_expires_at'];
        $payload['refresh']['phase'] = 'released';
        $payload['version']++;
        $this->replace($payload, $version, 'refreshing');
    }

    /** @param array<string, mixed> $payload */
    private function retireRefresh(#[\SensitiveParameter] array $payload): void
    {
        $version = $payload['version'];
        unset($payload['access_token'], $payload['refresh_token']);
        $payload['status'] = 'unusable';
        $payload['version']++;
        $payload['purge_at'] = time() + $this->tombstoneSeconds;
        $this->replace($payload, $version, 'refreshing');
    }

    public function cleanup(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new CredentialException('credential_configuration_error');
        }
        try {
            $this->autocommit();
            $rows = $this->db->table($this->table)->useWritePdo()
                ->where(function ($query) {
                    $query->where(function ($live) {
                        $live->whereNull('purge_at')->where('expires_at', '<=', time());
                    })->orWhere('purge_at', '<=', time());
                })->orderBy('id')->limit($limit)->get();
            $count = 0;
            foreach ($rows as $row) {
                try {
                    $payload = $this->read($row->id);
                    if ($payload['purge_at'] !== null && $payload['purge_at'] <= time()) {
                        $count += $this->db->table($this->table)->where('id', $row->id)->where('version', $payload['version'])
                            ->where('status', $payload['status'])->delete();
                    } elseif ($payload['purge_at'] === null && $payload['expires_at'] <= time()) {
                        if ($payload['status'] === 'refreshing' && $payload['absolute_expires_at'] > time()
                            && $payload['refresh']['context_expires_at'] > time() && $payload['refresh']['phase'] === 'claimed') {
                            // Durable absence of dispatch intent plus this CAS proves non-dispatch.
                            $this->releaseClaim($payload);
                        } elseif ($payload['status'] === 'refreshing' && $payload['refresh']['phase'] !== 'claimed') {
                            $payload['reason'] = 'refresh_outcome_uncertain';
                            $this->retireRefresh($payload);
                        } else {
                            $this->delete($row->id, $payload['version'], 'expired');
                        }
                        $count++;
                    }
                } catch (CredentialException $error) {
                    if ($error->category !== 'credential_lifecycle_failure') {
                        throw $error;
                    }
                }
            }

            return $count;
        } catch (CredentialException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new CredentialException('credential_store_unavailable');
        }
    }

    /** @return array<string, mixed> */
    private function read(#[\SensitiveParameter] string $id): array
    {
        try {
            $row = $this->db->table($this->table)->useWritePdo()->where('id', $id)->first();
            if ($row === null) {
                throw new CredentialException('incompatible_context');
            }
            $payload = $this->cipher->open($row->payload, $row->key_id);
            foreach (['id', 'provenance', 'status'] as $field) {
                if (($payload[$field] ?? null) !== $row->$field) {
                    throw new CredentialException('credential_store_integrity_failure');
                }
            }
            foreach (['version', 'expires_at', 'purge_at'] as $field) {
                $stored = $row->$field === null ? null : (int) $row->$field;
                if (! array_key_exists($field, $payload) || $payload[$field] !== $stored) {
                    throw new CredentialException('credential_store_integrity_failure');
                }
            }

            return $payload;
        } catch (CredentialException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new CredentialException('credential_store_unavailable');
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function columns(#[\SensitiveParameter] array $payload): array
    {
        return [...array_intersect_key($payload, array_flip(['id', 'provenance', 'status', 'version', 'expires_at', 'purge_at'])),
            'key_id' => $this->cipher->keyId(), 'payload' => $this->cipher->seal($payload)];
    }

    /** @param array<string, mixed> $payload */
    private function replace(#[\SensitiveParameter] array $payload, int $version, string $from, bool $live = false): void
    {
        try {
            $this->autocommit();
            $query = $this->db->table($this->table)->where('id', $payload['id'])->where('version', $version)->where('status', $from);
            if ($live) {
                $query->where('expires_at', '>', time());
            }
            if ($query->update($this->columns($payload)) !== 1) {
                throw new CredentialException('credential_lifecycle_failure');
            }
        } catch (CredentialException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new CredentialException('credential_store_unavailable');
        }
    }

    private function autocommit(): void
    {
        // Do not report durable success inside a host transaction that can later roll back.
        if ($this->db->transactionLevel() !== 0 || $this->db->getPdo()->inTransaction()) {
            throw new CredentialException('credential_store_unavailable');
        }
    }

    public function __debugInfo(): array
    {
        return ['store' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Credential store serialization is prohibited.');
    }
}
