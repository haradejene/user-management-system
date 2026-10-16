<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Fixtures;

use Doxa\Laravel\Contracts\ManagedTransactionStore;
use Doxa\Laravel\Transaction\AuthorizationTransaction;

final class FaultTransactionStore implements ManagedTransactionStore
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

    public function finish(string $state, bool $succeeded): void
    {
        $this->store->finish($state, $succeeded);
        if ($succeeded) {
            throw new \RuntimeException('REFRESH-SECRET');
        }
    }

    public function assertFinished(AuthorizationTransaction $transaction): void
    {
        $this->store->assertFinished($transaction);
    }
}
