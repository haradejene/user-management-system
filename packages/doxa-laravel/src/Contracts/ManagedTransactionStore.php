<?php

declare(strict_types=1);

namespace Doxa\Laravel\Contracts;

use Doxa\Laravel\Transaction\AuthorizationTransaction;

/** Explicit capability: preserve managed policy in the protected transaction across claim. */
interface ManagedTransactionStore extends TransactionStore
{
    public function assertFinished(#[\SensitiveParameter] AuthorizationTransaction $transaction): void;
}
