<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Integration;

use Doxa\Laravel\Authorization\Authorization;
use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Discovery\ProviderMetadata;
use Doxa\Laravel\Exceptions\DiscoveryException;
use Doxa\Laravel\Exceptions\DoxaException;
use Doxa\Laravel\Exceptions\ExpiredTransactionException;
use Doxa\Laravel\Exceptions\IdTokenValidationException;
use Doxa\Laravel\Exceptions\ReplayException;
use Doxa\Laravel\Exceptions\TokenExchangeException;
use Doxa\Laravel\Exceptions\TransactionException;
use Doxa\Laravel\Exceptions\UserInfoException;
use Doxa\Laravel\Identity\DoxaIdentity;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Token\CodeExchange;
use Doxa\Laravel\Transaction\AuthorizationTransaction;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SecurityRemediationTest extends TestCase
{
    public static function invalidTransactions(): array
    {
        return [['pending'], ['expired'], ['other client'], ['other issuer'], ['clone'], ['finished']];
    }

    #[DataProvider('invalidTransactions')]
    public function test_public_protocol_boundaries_reject_invalid_transactions(string $kind): void
    {
        $h = new Harness;
        try {
            $h->begin();
            $old = $h->transaction();
            $transaction = $old;
            if (in_array($kind, ['clone', 'finished'], true)) {
                $transaction = $h->store->claim($old->state, $old->browserBinding());
                if ($kind === 'clone') {
                    $transaction = clone $transaction;
                } else {
                    $h->store->finish($old->state, true);
                }
            } elseif ($kind !== 'pending') {
                $transaction = new AuthorizationTransaction($old->state, $old->nonce(), $old->verifier(), $kind === 'other issuer' ? 'https://other-iam.example.test' : $old->issuer,
                    $kind === 'other client' ? 'other-client' : $old->clientId, $old->redirectUri, $old->scopes,
                    time() - 600, $kind === 'expired' ? time() - 1 : time() + 10, $old->browserBinding(), $old->provider);
            }
            foreach (['exchange', 'identity'] as $boundary) {
                try {
                    if ($boundary === 'exchange') {
                        (new CodeExchange($h->config, $h->http, $h->store))->exchange('CODE', $transaction);
                    } else {
                        DoxaIdentity::authenticate($h->validator, $h->jwt(['aud' => $transaction->clientId]), $transaction);
                    }
                    self::fail('Invalid transaction accepted');
                } catch (DoxaException) {
                    self::assertSame(0, $h->http->count(Harness::TOKEN));
                    self::assertSame(0, $h->http->count(Harness::JWKS));
                }
            }
        } finally {
            $h->cleanup();
        }
    }

    public function test_fabricated_transaction_cannot_be_laundered_through_store_creation(): void
    {
        $h = new Harness;
        try {
            $h->begin();
            $transaction = $h->transaction();
            $key = 'doxa.transaction.'.hash('sha256', $transaction->state);
            $original = $h->cache->get($key);
            try {
                $h->store->create($transaction);
                self::fail('Unissued transaction stored');
            } catch (TransactionException) {
                self::assertSame($original, $h->cache->get($key));
                self::assertSame(0, $h->http->count(Harness::TOKEN));
            }
            self::assertSame('public-subject', $h->client->handleCallback($h->callback())->subject());
            self::assertSame(1, $h->http->count(Harness::TOKEN));
        } finally {
            $h->cleanup();
        }
    }

    public function test_cloning_sdk_created_transaction_cannot_acquire_creation_provenance(): void
    {
        $h = new Harness;
        try {
            $authorization = new Authorization($h->discovery);
            $original = $authorization->create($h->config, $h->discovery->discover(), hash('sha256', $h->session->getId()));
            try {
                $h->store->create(clone $original);
                self::fail('Clone acquired creation provenance');
            } catch (TransactionException) {
                self::assertNull($h->cache->get('doxa.transaction.'.hash('sha256', $original->state)));
            }
            $h->store->create($original);
            parse_str(parse_url($authorization->url($original), PHP_URL_QUERY), $h->query);
            self::assertSame('public-subject', $h->client->handleCallback($h->callback())->subject());
            self::assertSame(1, $h->http->count(Harness::TOKEN));
        } finally {
            $h->cleanup();
        }
    }

    public function test_identity_and_userinfo_expose_only_intended_allowlisted_values(): void
    {
        $h = new Harness(['userinfo_enabled' => true, 'scopes' => ['openid', 'profile', 'email']]);
        try {
            $h->begin();
            $h->claimChanges = ['name' => 'Original', 'email' => 'person@example.test', 'email_verified' => false,
                'roles' => ['UNEXPOSED-ROLE'], 'access_token' => 'UNEXPOSED-TOKEN', 'internal_id' => 'UNEXPOSED-ID'];
            $h->http->responses[Harness::USERINFO] = ['sub' => 'public-subject', 'name' => 'Updated',
                'given_name' => 'Given', 'family_name' => 'Family', 'picture' => 'https://images.example.test/avatar',
                'password' => 'UNEXPOSED-PASSWORD', 'refresh_token' => 'UNEXPOSED-REFRESH', 'roles' => ['UNEXPOSED-ROLE']];
            $identity = $h->client->handleCallback($h->callback());
            self::assertSame(['issuer' => Harness::ISSUER, 'subject' => 'public-subject', 'email' => 'person@example.test',
                'email_verified' => false, 'name' => 'Updated', 'given_name' => 'Given', 'family_name' => 'Family',
                'picture' => 'https://images.example.test/avatar'], $identity->jsonSerialize());
            self::assertStringNotContainsString('UNEXPOSED', json_encode($identity, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('Updated', print_r($identity, true));
            self::assertStringNotContainsString('person@example.test', print_r($identity, true));
            self::assertFalse(method_exists($identity, '__toString'));
            $this->expectException(\LogicException::class);
            serialize($identity);
        } finally {
            $h->cleanup();
        }
    }

    public function test_authorization_rejects_substituted_provider_endpoints(): void
    {
        $h = new Harness;
        try {
            $provider = ProviderMetadata::fromArray(
                [...Harness::metadata(), 'token_endpoint' => 'https://attacker.example.test/token'], $h->config);
            $this->expectException(DiscoveryException::class);
            (new Authorization($h->discovery))->create($h->config, $provider, 'binding');
        } finally {
            $h->cleanup();
        }
    }

    public function test_direct_exchange_is_single_use_even_with_a_new_service_instance(): void
    {
        $h = new Harness;
        try {
            $h->begin();
            $transaction = $h->store->claim($h->query['state'], hash('sha256', $h->session->getId()));
            (new CodeExchange($h->config, $h->http, $h->store))->exchange('CODE', $transaction);
            try {
                (new CodeExchange($h->config, $h->http, $h->store))->exchange('CODE', $transaction);
                self::fail('Repeated exchange accepted');
            } catch (ReplayException $exception) {
                self::assertSame('transaction_replay', $exception->category);
                self::assertSame(1, $h->http->count(Harness::TOKEN));
            }
        } finally {
            $h->cleanup();
        }
    }

    public function test_uncertain_direct_exchange_cannot_be_retried(): void
    {
        $h = new Harness;
        try {
            $h->begin();
            $transaction = $h->store->claim($h->query['state'], hash('sha256', $h->session->getId()));
            $h->http->responses[Harness::TOKEN] = new \RuntimeException('Uncertain exchange');
            try {
                (new CodeExchange($h->config, $h->http, $h->store))->exchange('CODE', $transaction);
                self::fail('Transport failure accepted');
            } catch (TokenExchangeException $exception) {
                self::assertSame('token_exchange_failure', $exception->category);
            }
            try {
                (new CodeExchange($h->config, $h->http, $h->store))->exchange('CODE', $transaction);
                self::fail('Uncertain exchange retried');
            } catch (ReplayException) {
                self::assertSame(1, $h->http->count(Harness::TOKEN));
            }
        } finally {
            $h->cleanup();
        }
    }

    public static function wrongConfiguredContext(): array
    {
        return [['client_id', 'other-client'], ['redirect_uri', 'https://other.example.test/callback'], ['issuer', 'https://other-iam.example.test']];
    }

    #[DataProvider('wrongConfiguredContext')]
    public function test_claimed_transaction_cannot_be_used_with_another_configured_context(string $field, string $value): void
    {
        $h = new Harness;
        try {
            $h->begin();
            $transaction = $h->store->claim($h->query['state'], hash('sha256', $h->session->getId()));
            $config = new DoxaConfig([...Harness::config(), $field => $value]);
            try {
                (new CodeExchange($config, $h->http, $h->store))->exchange('CODE', $transaction);
                self::fail('Configured context substitution accepted');
            } catch (DoxaException) {
                self::assertSame(0, $h->http->count(Harness::TOKEN));
            }
            $tokens = (new CodeExchange($h->config, $h->http, $h->store))->exchange('CODE', $transaction);
            self::assertNotEmpty($tokens->idToken());
            self::assertSame(1, $h->http->count(Harness::TOKEN));
        } finally {
            $h->cleanup();
        }
    }

    public static function untrustedObjects(): array
    {
        return [['unclaimed'], ['clone']];
    }

    #[DataProvider('untrustedObjects')]
    public function test_rejecting_untrusted_object_does_not_consume_the_valid_claim(string $kind): void
    {
        $h = new Harness;
        try {
            $h->begin();
            $pending = $h->transaction();
            $transaction = $h->store->claim($pending->state, $pending->browserBinding());
            $untrusted = $kind === 'clone' ? clone $transaction : $pending;
            try {
                (new CodeExchange($h->config, $h->http, $h->store))->exchange('CODE', $untrusted);
                self::fail('Untrusted object accepted');
            } catch (DoxaException) {
                self::assertSame(0, $h->http->count(Harness::TOKEN));
            }
            $tokens = (new CodeExchange($h->config, $h->http, $h->store))->exchange('CODE', $transaction);
            self::assertNotEmpty($tokens->idToken());
            self::assertSame(1, $h->http->count(Harness::TOKEN));
        } finally {
            $h->cleanup();
        }
    }

    public function test_claimed_transaction_expiring_before_exchange_is_rejected(): void
    {
        $h = new Harness;
        try {
            $h->begin();
            $old = $h->transaction();
            $short = new AuthorizationTransaction($old->state, $old->nonce(), $old->verifier(), $old->issuer,
                $old->clientId, $old->redirectUri, $old->scopes, time(), time() + 3, $old->browserBinding(), $old->provider);
            $h->cache->forget('doxa.transaction.'.hash('sha256', $old->state));
            $h->cache->put('doxa.transaction.'.hash('sha256', $old->state), (new Encrypter(str_repeat('k', 32), 'AES-256-CBC'))->encrypt($short), 60);
            $transaction = $h->store->claim($short->state, $short->browserBinding());
            sleep(4);
            foreach (['exchange', 'identity'] as $boundary) {
                try {
                    if ($boundary === 'exchange') {
                        (new CodeExchange($h->config, $h->http, $h->store))->exchange('CODE', $transaction);
                    } else {
                        DoxaIdentity::authenticate($h->validator, $h->jwt(), $transaction);
                    }
                    self::fail('Expired claim accepted');
                } catch (ExpiredTransactionException $exception) {
                    self::assertSame('transaction_expired', $exception->category);
                    self::assertSame(0, $h->http->count(Harness::TOKEN));
                }
            }
        } finally {
            $h->cleanup();
        }
    }

    public static function malformedClaims(): array
    {
        return [['idtoken', 'email_verified'], ['idtoken', 'picture'], ['userinfo', 'email_verified'], ['userinfo', 'name'], ['userinfo_response', 'subject'], ['userinfo_response', 'media_type']];
    }

    #[DataProvider('malformedClaims')]
    public function test_malformed_claims_have_safe_trace_arguments_and_correct_category(string $source, string $field): void
    {
        $process = new Process([PHP_BINARY, '-d', 'zend.exception_ignore_args=0',
            dirname(__DIR__).'/Fixtures/claim-trace.php', $source, $field]);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('0', $result['ignore_args']);
        self::assertNotContains('Doxa\\Laravel\\Identity\\DoxaIdentity::attributes', $result['functions']);
        self::assertSame($source !== 'idtoken' ? UserInfoException::class : IdTokenValidationException::class, $result['class']);
        self::assertSame($source !== 'idtoken' ? 'userinfo_failure' : 'identity_extraction_failure', $result['category']);
        self::assertTrue($result['has_arguments']);
        self::assertNull($result['previous']);
        foreach (['private.person@example.test', 'Private Person', 'PrivateGiven', 'PrivateFamily',
            'https://images.example.test/private-avatar', 'private-public-subject', 'malformed-private-value'] as $value) {
            self::assertStringNotContainsString($value, $result['arguments']);
        }
        self::assertSame('transaction_replay', $result['replay']);
    }
}
