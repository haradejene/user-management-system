<?php

declare(strict_types=1);

namespace Doxa\Laravel\Transaction;

use Doxa\Laravel\Authorization\Authorization;
use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Contracts\TransactionStore;
use Doxa\Laravel\Exceptions\CallbackException;
use Doxa\Laravel\Exceptions\ExpiredTransactionException;
use Doxa\Laravel\Exceptions\ReplayException;
use Doxa\Laravel\Exceptions\TransactionException;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Encryption\Encrypter;

final class CacheTransactionStore implements TransactionStore
{
    /** @var \WeakMap<AuthorizationTransaction, bool> */
    private \WeakMap $claimed;

    public function __construct(#[\SensitiveParameter] private readonly Repository $cache, #[\SensitiveParameter] private readonly Encrypter $encryption, #[\SensitiveParameter] private readonly DoxaConfig $config)
    {
        $this->claimed = new \WeakMap;
        // These drivers implement atomic add across processes, not just workers' memory.
        if (! $cache->getStore() instanceof DatabaseStore && ! $cache->getStore() instanceof RedisStore && ! $cache->getStore() instanceof FileStore) {
            throw new TransactionException('transaction_storage_failure');
        }
    }

    public function create(#[\SensitiveParameter] AuthorizationTransaction $transaction): void
    {
        try {
            if (! Authorization::issued($transaction) || $transaction->status !== 'pending'
                || $transaction->issuer !== $this->config->issuer || $transaction->clientId !== $this->config->clientId
                || $transaction->redirectUri !== $this->config->redirectUri || $transaction->scopes !== $this->config->scopes
                || $transaction->provider->issuer !== $this->config->issuer || $transaction->createdAt > time()
                || $transaction->expiresAt <= time() || $transaction->expiresAt > $transaction->createdAt + 600
                || ! $this->cache->add($this->key($transaction->state), $this->encryption->encrypt($transaction), $transaction->expiresAt - time() + 60)) {
                throw new TransactionException('transaction_storage_failure');
            }
        } catch (\Throwable) {
            throw new TransactionException('transaction_storage_failure');
        }
    }

    public function claim(#[\SensitiveParameter] string $state, #[\SensitiveParameter] string $binding): AuthorizationTransaction
    {
        try {
            $marker = $this->cache->get($this->key($state).'.claim');
            if (is_array($marker)) {
                if (! hash_equals($marker['binding'], hash('sha256', $binding))) {
                    throw new CallbackException('invalid_state');
                }
                if ($marker['expires'] <= time()) {
                    throw new ExpiredTransactionException('transaction_expired');
                }
                throw new ReplayException('transaction_replay');
            }
            $value = $this->cache->get($this->key($state));
            if (! is_string($value)) {
                throw new CallbackException('invalid_state');
            }
            $transaction = $this->encryption->decrypt($value);
            if (! $transaction instanceof AuthorizationTransaction || ! hash_equals($transaction->state, $state)
                || ! hash_equals($transaction->browserBinding(), $binding)) {
                throw new CallbackException('invalid_state');
            }
            if ($transaction->expiresAt <= time()) {
                $this->cache->forget($this->key($state));
                throw new ExpiredTransactionException('transaction_expired');
            }
            if ($transaction->status !== 'pending' || $transaction->createdAt > time()
                || $transaction->expiresAt > $transaction->createdAt + 600
                || $transaction->issuer !== $this->config->issuer || $transaction->clientId !== $this->config->clientId
                || $transaction->redirectUri !== $this->config->redirectUri || $transaction->scopes !== $this->config->scopes
                || $transaction->provider->issuer !== $this->config->issuer) {
                throw new TransactionException('authorization_error');
            }
            if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $transaction->verifier()) !== 1
                || preg_match('/^[A-Za-z0-9_-]{43}$/D', $transaction->nonce()) !== 1) {
                throw new TransactionException('invalid_pkce_transaction');
            }
            // Atomic insert is a durable claim, NOT a lease. Never release/delete it.
            // A crashed/paused claimant cannot admit a second exchange after lock expiry.
            if (! $this->cache->add($this->key($state).'.claim', ['binding' => hash('sha256', $binding),
                'expires' => $transaction->expiresAt, 'status' => 'processing'], $transaction->expiresAt - time() + 60)) {
                throw new ReplayException('transaction_replay');
            }
            if (! $this->cache->forget($this->key($state))) {
                throw new TransactionException('transaction_storage_failure');
            } // Secrets exist only in claimant memory now.

            $this->claimed[$transaction] = true;

            return $transaction;
        } catch (CallbackException|TransactionException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new TransactionException('transaction_storage_failure');
        }
    }

    public function assertClaimed(#[\SensitiveParameter] AuthorizationTransaction $transaction): void
    {
        if ($transaction->expiresAt <= time()) {
            throw new ExpiredTransactionException('transaction_expired');
        }
        if (! isset($this->claimed[$transaction]) || $transaction->issuer !== $this->config->issuer
            || $transaction->clientId !== $this->config->clientId || $transaction->redirectUri !== $this->config->redirectUri
            || $transaction->scopes !== $this->config->scopes || $transaction->provider->issuer !== $this->config->issuer) {
            throw new TransactionException('authorization_error');
        }
        try {
            $marker = $this->cache->get($this->key($transaction->state).'.claim');
            if (! is_array($marker) || ($marker['status'] ?? null) !== 'processing') {
                throw new ReplayException('transaction_replay');
            }
        } catch (TransactionException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new TransactionException('transaction_storage_failure');
        }
    }

    public function consume(#[\SensitiveParameter] AuthorizationTransaction $transaction): void
    {
        $this->assertClaimed($transaction);
        try {
            // Irreversible across workers, including uncertain transport outcomes.
            if (! $this->cache->add($this->key($transaction->state).'.exchange', true, max(1, $transaction->expiresAt - time() + 60))) {
                throw new ReplayException('transaction_replay');
            }
        } catch (TransactionException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new TransactionException('transaction_storage_failure');
        }
    }

    public function finish(#[\SensitiveParameter] string $state, bool $succeeded): void
    {
        try {
            $marker = $this->cache->get($this->key($state).'.claim');
            if (! is_array($marker)) {
                throw new TransactionException('transaction_storage_failure');
            }
            $marker['status'] = $succeeded ? 'succeeded' : 'failed';
            if (! $this->cache->put($this->key($state).'.claim', $marker, max(1, $marker['expires'] - time() + 60))) {
                throw new TransactionException('transaction_storage_failure');
            }
            $this->cache->forget($this->key($state));
        } catch (\Throwable) {
            throw new TransactionException('transaction_storage_failure');
        }
    }

    private function key(string $state): string
    {
        return 'doxa.transaction.'.hash('sha256', $state);
    }
}
