<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Fixtures;

use Doxa\Laravel\Contracts\ManagedTransactionStore;
use Doxa\Laravel\Transaction\AuthorizationTransaction;

/** Independent-process crash injection after credential staging, before callback finalization. */
final class CrashTransactionStore implements ManagedTransactionStore
{
    public function __construct(private ManagedTransactionStore $store) {}

    public function create(AuthorizationTransaction $transaction): void
    {
        $this->store->create($transaction);
    }

    public function claim(string $state, string $binding): AuthorizationTransaction
    {
        return $this->store->claim($state, $binding);
    }

    public function assertClaimed(AuthorizationTransaction $transaction): void
    {
        $this->store->assertClaimed($transaction);
    }

    public function consume(AuthorizationTransaction $transaction): void
    {
        $this->store->consume($transaction);
    }

    public function assertFinished(AuthorizationTransaction $transaction): void
    {
        $this->store->assertFinished($transaction);
    }

    public function finish(string $state, bool $succeeded): void
    {
        if ($succeeded) {
            echo 'crashed-after-stage';
            exit(0);
        }
        $this->store->finish($state, false);
    }
}
