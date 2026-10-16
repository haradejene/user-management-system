<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Fixtures;

use Doxa\Laravel\Contracts\CredentialStore;

final class FaultCredentialStore implements CredentialStore
{
    public function __construct(private CredentialStore $store, public string $fault) {}

    private bool $outage = false;

    public function ready(): void
    {
        $this->store->ready();
    }

    public function stage(array $payload): void
    {
        if ($this->fault === 'insert') {
            throw new \RuntimeException('ACCESS-SECRET');
        }
        $this->store->stage($payload);
        if ($this->fault === 'commit') {
            throw new \RuntimeException('REFRESH-SECRET');
        }
    }

    public function metadata(string $id): array
    {
        if ($this->outage) {
            throw new \RuntimeException('storage unavailable');
        }

        return $this->store->metadata($id);
    }

    public function transition(string $id, int $version, string $from, string $to, array $association = []): void
    {
        if ($this->fault === 'activation' && $from === 'staged') {
            throw new \RuntimeException('ACCESS-SECRET');
        }
        $this->store->transition($id, $version, $from, $to, $association);
        if ($this->fault === 'activation_commit' && $from === 'staged') {
            throw new \RuntimeException('REFRESH-SECRET');
        }
        if ($this->fault === 'binding_claim_crash' && $to === 'binding') {
            echo 'crashed-after-binding-claim';
            exit(0);
        }
        if ($this->fault === 'binding_crash' && $to === 'active') {
            echo 'crashed-after-binding-write';
            exit(0);
        }
        if ($this->fault === 'binding_outage' && $to === 'active') {
            $this->outage = true;
            throw new \RuntimeException('lost acknowledgement');
        }
        if ($this->fault === 'binding_commit' && $to === 'active') {
            throw new \RuntimeException('ACCESS-SECRET');
        }
    }

    public function delete(string $id, int $version, string $terminal = 'deleted'): void
    {
        if ($this->outage) {
            throw new \RuntimeException('storage unavailable');
        }
        $this->store->delete($id, $version, $terminal);
    }

    public function abandonBinding(string $id, int $version, string $attempt): void
    {
        if ($this->outage) {
            throw new \RuntimeException('storage unavailable');
        }
        $this->store->abandonBinding($id, $version, $attempt);
    }

    public function cleanup(int $limit = 100): int
    {
        return $this->store->cleanup($limit);
    }
}
