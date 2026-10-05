<?php

declare(strict_types=1);

namespace Doxa\Laravel\Contracts;

use Doxa\Laravel\Transaction\AuthorizationTransaction;

interface TransactionStore
{
    public function create(#[\SensitiveParameter] AuthorizationTransaction $transaction): void;

    public function claim(#[\SensitiveParameter] string $state, #[\SensitiveParameter] string $binding): AuthorizationTransaction;

    public function assertClaimed(#[\SensitiveParameter] AuthorizationTransaction $transaction): void;

    public function consume(#[\SensitiveParameter] AuthorizationTransaction $transaction): void;

    public function finish(#[\SensitiveParameter] string $state, bool $succeeded): void;
}
