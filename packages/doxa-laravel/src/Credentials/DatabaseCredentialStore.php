<?php

declare(strict_types=1);

namespace Doxa\Laravel\Credentials;

use Doxa\Laravel\Contracts\CredentialCipher;
use Doxa\Laravel\Contracts\CredentialStore;
use Doxa\Laravel\Exceptions\CredentialException;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;

final class DatabaseCredentialStore implements CredentialStore
{
    private readonly Connection $db;

    public function __construct(
        #[\SensitiveParameter] ConnectionInterface $db,
        #[\SensitiveParameter] private readonly CredentialCipher $cipher,
        private readonly string $table = 'doxa_credential_contexts',
        private readonly int $tombstoneSeconds = 3600,
    ) {
        if (! $db instanceof Connection || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $table) !== 1 || $tombstoneSeconds < 60 || $tombstoneSeconds > 604800) {
            throw new CredentialException('credential_configuration_error');
        }
        if ($db->getDriverName() === 'sqlite' && ($db->getDatabaseName() === ':memory:'
            || str_contains($db->getDatabaseName(), 'mode=memory') || str_contains($db->getDatabaseName(), 'file::memory:'))) {
            throw new CredentialException('credential_configuration_error');
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
                        $this->delete($row->id, $payload['version'], 'expired');
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
