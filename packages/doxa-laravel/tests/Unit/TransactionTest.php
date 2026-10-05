<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Unit;

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Exceptions\DoxaException;
use Doxa\Laravel\Exceptions\TransactionException;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Transaction\AuthorizationTransaction;
use Doxa\Laravel\Transaction\CacheTransactionStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionTest extends TestCase
{
    public static function replacements(): array
    {
        return [['state'], ['nonce'], ['verifier'], ['valid verifier mismatch'], ['issuer'], ['client'], ['redirect'], ['scopes'], ['status']];
    }

    #[DataProvider('replacements')]
    public function test_substitution_does_not_authenticate(string $field): void
    {
        $h = new Harness;
        try {
            $h->begin();
            $old = $h->transaction();
            $transaction = new AuthorizationTransaction(
                $field === 'state' ? str_repeat('z', 43) : $old->state,
                $field === 'nonce' ? str_repeat('z', 43) : $old->nonce(),
                $field === 'verifier' ? 'malformed' : ($field === 'valid verifier mismatch' ? str_repeat('z', 43) : $old->verifier()),
                $field === 'issuer' ? 'https://attacker.test' : $old->issuer,
                $field === 'client' ? 'other' : $old->clientId,
                $field === 'redirect' ? 'https://attacker.test' : $old->redirectUri,
                $field === 'scopes' ? ['openid', 'email'] : $old->scopes,
                $old->createdAt, $old->expiresAt, $old->browserBinding(), $old->provider,
                $field === 'status' ? 'succeeded' : 'pending');
            $h->cache->put('doxa.transaction.'.hash('sha256', $old->state), (new Encrypter(str_repeat('k', 32), 'AES-256-CBC'))->encrypt($transaction), 600);
            $this->expectException(DoxaException::class);
            $h->client->handleCallback($h->callback());
        } finally {
            $h->cleanup();
        }
    }

    public function test_pending_secrets_are_encrypted_and_expiry_is_bounded(): void
    {
        $h = new Harness;
        try {
            $query = $h->begin();
            $transaction = $h->transaction();
            $stored = $h->cache->get('doxa.transaction.'.hash('sha256', $query['state']));
            self::assertStringNotContainsString($transaction->nonce(), $stored);
            self::assertStringNotContainsString($transaction->verifier(), $stored);
            self::assertSame(600, $transaction->expiresAt - $transaction->createdAt);
            self::assertSame(hash('sha256', $h->session->getId()), $transaction->browserBinding());
            self::assertStringNotContainsString($transaction->verifier(), print_r($transaction, true));
        } finally {
            $h->cleanup();
        }
    }

    public function test_process_local_cache_is_rejected(): void
    {
        $this->expectException(TransactionException::class);
        new CacheTransactionStore(new Repository(new ArrayStore),
            new Encrypter(str_repeat('k', 32), 'AES-256-CBC'), new DoxaConfig(Harness::config()));
    }
}
