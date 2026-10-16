<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Integration;

use Doxa\Laravel\Authorization\AuthorizationOptions;
use Doxa\Laravel\Credentials\CredentialContext;
use Doxa\Laravel\Credentials\CredentialSchema;
use Doxa\Laravel\Credentials\DatabaseCredentialStore;
use Doxa\Laravel\Credentials\LaravelCredentialCipher;
use Doxa\Laravel\Credentials\ManagedLoginPolicy;
use Doxa\Laravel\Exceptions\CredentialException;
use Doxa\Laravel\Exceptions\DoxaException;
use Doxa\Laravel\Identity\DoxaIdentity;
use Doxa\Laravel\Tests\Fixtures\CredentialFixture;
use Doxa\Laravel\Tests\Fixtures\FaultCredentialStore;
use Doxa\Laravel\Tests\Fixtures\FaultTransactionStore;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Token\CodeExchange;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ManagedCredentialsTest extends TestCase
{
    private CredentialFixture $f;

    protected function setUp(): void
    {
        $this->f = new CredentialFixture;
    }

    protected function tearDown(): void
    {
        $this->f->cleanup();
    }

    public function test_ac22_identity_only_does_not_resolve_managed_services_or_retain_tokens(): void
    {
        $this->f->db->getSchemaBuilder()->drop($this->f->table);
        $h = $this->f->login;
        $h->begin();
        $identity = $h->client->handleCallback($h->callback());
        self::assertSame('public-subject', $identity->subject());
        self::assertStringNotContainsString('SECRET', json_encode($identity));
        // Restore only fixture table for teardown; identity-only used no credential table/key/adapter.
        CredentialSchema::create($this->f->db->getSchemaBuilder(), $this->f->table);
        self::assertSame(0, $this->f->db->table($this->f->table)->count());
    }

    public function test_ac23_durable_encrypted_managed_result_survives_new_services(): void
    {
        $this->f->begin();
        $result = $this->f->result();
        $id = $result->credentialContext()->serverReference();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $id);
        self::assertSame('public-subject', $result->identity()->subject());
        $raw = json_encode($this->f->db->table($this->f->table)->first());
        foreach (['ACCESS-SECRET', 'REFRESH-SECRET', 'public-subject', 'admin-1'] as $secret) {
            self::assertStringNotContainsString($secret, $raw);
        }
        self::assertSame('ACCESS-SECRET', $this->f->payload($id)['access_token']);
        self::assertSame('REFRESH-SECRET', $this->f->payload($id)['refresh_token']);
        $fresh = new DatabaseCredentialStore($this->f->db, new LaravelCredentialCipher(str_repeat('k', 32), 'test-key'), $this->f->table);
        self::assertSame('awaiting_binding', $fresh->metadata($id)['status']);
        self::assertArrayNotHasKey('access_token', $fresh->metadata($id));
        self::assertArrayNotHasKey('refresh_token', $fresh->metadata($id));
        self::assertArrayNotHasKey('id_token', $this->f->payload($id));
        self::assertLessThanOrEqual(time() + 900, $fresh->metadata($id)['access_expires_at']);
        self::assertSame(1, $this->f->login->http->count(Harness::TOKEN));
        $this->expectException(DoxaException::class);
        $this->f->result();
    }

    public static function mismatches(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('mismatches')]
    public function test_ac22_handlers_cannot_upgrade_or_downgrade_stored_policy(bool $managed): void
    {
        $managed ? $this->f->begin() : $this->f->login->begin();
        try {
            $managed ? $this->f->client->handleCallback($this->f->login->callback()) : $this->f->result();
            self::fail('Mismatched handler accepted');
        } catch (CredentialException $error) {
            self::assertSame('credential_policy_mismatch', $error->category);
        }
        self::assertSame(0, $this->f->login->http->count(Harness::TOKEN));
        $this->expectException(DoxaException::class);
        $managed ? $this->f->result() : $this->f->login->client->handleCallback($this->f->login->callback());
    }

    public function test_browser_input_cannot_select_retention_purpose_or_owner(): void
    {
        $this->f->login->begin();
        $h = $this->f->login;
        $identity = $h->client->handleCallback($h->request('state='.$h->query['state'].'&code=CODE&managed=1&purpose=pending_link&owner=victim'));
        self::assertSame('public-subject', $identity->subject());
        self::assertSame(0, $this->f->db->table($this->f->table)->count());
    }

    public static function faults(): array
    {
        return array_map(fn ($fault) => [$fault], ['insert', 'commit', 'activation', 'activation_commit', 'finish']);
    }

    #[DataProvider('faults')]
    public function test_ac23_storage_and_finalization_faults_return_no_result_and_never_reexchange(string $fault): void
    {
        $this->f->configure(new FaultCredentialStore($this->f->store, $fault),
            $fault === 'finish' ? new FaultTransactionStore($this->f->login->store) : null);
        $this->f->begin();
        try {
            $this->f->result();
            self::fail('Fault returned success');
        } catch (CredentialException $error) {
            self::assertStringNotContainsString('SECRET', (string) $error);
            self::assertNull($error->getPrevious());
        }
        self::assertSame(1, $this->f->login->http->count(Harness::TOKEN));
        foreach ($this->f->db->table($this->f->table)->get() as $row) {
            self::assertContains($row->status, ['staged', 'unusable']);
        }
        $trace = new Process([PHP_BINARY, '-d', 'zend.exception_ignore_args=0',
            dirname(__DIR__).'/Fixtures/managed-error-trace.php', $fault], timeout: 30);
        $trace->mustRun();
        self::assertSame(['clean' => true, 'previous' => true, 'args_enabled' => true], json_decode($trace->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        try {
            $this->f->result();
            self::fail('Retried exchange');
        } catch (DoxaException) {
            self::assertSame(1, $this->f->login->http->count(Harness::TOKEN));
        }
    }

    public function test_ac25_missing_table_fails_before_redirect_or_token_exchange(): void
    {
        $this->f->db->getSchemaBuilder()->rename($this->f->table, $this->f->table.'_saved');
        try {
            $this->f->begin();
            self::fail('Missing table accepted');
        } catch (CredentialException) {
            self::assertSame([], $this->f->login->http->requests);
        } finally {
            $this->f->db->getSchemaBuilder()->rename($this->f->table.'_saved', $this->f->table);
        }
    }

    public static function keys(): array
    {
        return [['', 'test'], ['short', 'test'], [str_repeat('k', 32), '']];
    }

    #[DataProvider('keys')]
    public function test_ac25_explicit_key_and_identifier_required(string $key, string $identifier): void
    {
        $this->expectException(CredentialException::class);
        new LaravelCredentialCipher($key, $identifier);
    }

    public static function substitutions(): array
    {
        return [['payload'], ['status'], ['version'], ['expires_at'], ['key_id'], ['provenance']];
    }

    #[DataProvider('substitutions')]
    public function test_ac25_ciphertext_and_operational_metadata_substitution_fails_closed(string $field): void
    {
        $this->f->begin();
        $one = $this->f->result()->credentialContext()->serverReference();
        $this->f->begin();
        $two = $this->f->result()->credentialContext()->serverReference();
        $value = match ($field) {
            'payload' => $this->f->db->table($this->f->table)->where('id', $two)->value('payload'),
            'version' => 99,
            'expires_at' => time() + 999,
            'status' => 'active',
            default => 'substituted',
        };
        $this->f->db->table($this->f->table)->where('id', $one)->update([$field => $value]);
        $this->expectException(CredentialException::class);
        $this->f->store->metadata($one);
    }

    public function test_wrong_decryption_key_is_not_plaintext_fallback(): void
    {
        $this->f->begin();
        $id = $this->f->result()->credentialContext()->serverReference();
        $wrong = new DatabaseCredentialStore($this->f->db, new LaravelCredentialCipher(str_repeat('z', 32), 'test-key'), $this->f->table);
        $this->expectException(CredentialException::class);
        $wrong->metadata($id);
    }

    public function test_ac26_binding_and_explicit_trusted_session_regeneration_do_not_transfer_owners(): void
    {
        $this->f->begin();
        $context = $this->f->result()->credentialContext();
        $this->f->login->session->setId(str_repeat('b', 40));
        $this->f->host->allowRegeneration = true;
        $context = $this->f->client->bindCredentialContext($context);
        $record = $this->f->store->metadata($context->recordId());
        self::assertSame('active', $record['status']);
        self::assertSame('local-7', $record['owner']['account']);
        $this->f->host->account = 'other-local-user';
        $this->expectException(CredentialException::class);
        $this->f->client->restoreCredentialContext($context->serverReference());
    }

    public static function ownership(): array
    {
        return [['session'], ['initiator'], ['target'], ['subject'], ['issuer'], ['client']];
    }

    #[DataProvider('ownership')]
    public function test_ac26_identifier_alone_or_foreign_binding_is_not_authorization(string $field): void
    {
        $this->f->begin();
        $context = $this->f->result()->credentialContext();
        if ($field === 'session') {
            $this->f->login->session->setId(str_repeat('x', 40));
        } elseif (in_array($field, ['initiator', 'target'], true)) {
            $this->f->host->$field = 'another';
        } else {
            $this->f->mutate($context->serverReference(), [$field => 'another']);
        }
        $this->expectException(CredentialException::class);
        $this->f->client->deleteCredentialContext(CredentialContext::fromServerReference($context->serverReference()));
    }

    public function test_active_owner_cannot_be_rebound_to_another_account(): void
    {
        $this->f->begin();
        $context = $this->f->result()->credentialContext();
        $context = $this->f->client->bindCredentialContext($context);
        $this->expectException(CredentialException::class);
        $context = $this->f->client->bindCredentialContext($context);
    }

    public function test_pending_link_is_protected_and_cannot_be_completed_in_milestone_a(): void
    {
        $this->f->begin(ManagedLoginPolicy::pendingLink('link-1'));
        $context = $this->f->result()->credentialContext();
        self::assertSame('pending_link', $this->f->store->metadata($context->recordId())['status']);
        $this->expectException(CredentialException::class);
        $context = $this->f->client->bindCredentialContext($context);
    }

    public static function pendingBindings(): array
    {
        return [['initiator', ''], ['target', ''], ['link', 'different'], ['deadline', -1], ['deadline', PHP_INT_MAX]];
    }

    #[DataProvider('pendingBindings')]
    public function test_invalid_pending_link_bindings_fail_before_authorization(string $field, string|int $value): void
    {
        $this->f->host->$field = $value;
        try {
            $this->f->begin(ManagedLoginPolicy::pendingLink('link-1'));
            self::fail('Invalid linking context accepted');
        } catch (CredentialException) {
            self::assertSame([], $this->f->login->http->requests);
        }
    }

    public function test_cancellation_and_expiration_prevent_restore_and_stale_binding(): void
    {
        $this->f->begin();
        $context = $this->f->result()->credentialContext();
        $this->f->client->deleteCredentialContext($context);
        $this->f->client->deleteCredentialContext($context);
        $record = $this->f->payload($context->recordId());
        self::assertSame('deleted', $record['status']);
        self::assertArrayNotHasKey('refresh_token', $record);
        try {
            $this->f->store->transition($context->serverReference(), 2, 'awaiting_binding', 'active', ['account' => 'local-7', 'session' => str_repeat('a', 64)]);
            self::fail('Stale write revived deleted row');
        } catch (CredentialException) {
            self::assertSame('deleted', $this->f->store->metadata($context->recordId())['status']);
        }
        $this->expectException(CredentialException::class);
        $this->f->client->restoreCredentialContext($context->serverReference());
    }

    public function test_expired_and_staged_orphans_are_cleaned_without_reactivation(): void
    {
        $this->f->configure(new FaultCredentialStore($this->f->store, 'commit'));
        $this->f->begin();
        try {
            $this->f->result();
        } catch (CredentialException) {
        }
        $row = $this->f->db->table($this->f->table)->first();
        self::assertSame('staged', $row->status);
        $this->f->mutate($row->id, ['expires_at' => time() - 1]);
        self::assertSame(1, $this->f->store->cleanup());
        self::assertArrayNotHasKey('access_token', $this->f->payload($row->id));
        $this->f->mutate($row->id, ['purge_at' => time() - 1]);
        self::assertSame(1, $this->f->store->cleanup());
        self::assertSame(0, $this->f->db->table($this->f->table)->count());
    }

    public function test_ac24_results_and_contexts_cannot_serialize_or_dump_credentials(): void
    {
        $this->f->begin();
        $result = $this->f->result();
        foreach ([$result, $result->credentialContext()] as $protected) {
            self::assertStringNotContainsString('SECRET', print_r($protected, true));
            foreach (['serialize', 'json_encode'] as $encode) {
                try {
                    $encode($protected);
                    self::fail('Serialization allowed');
                } catch (\LogicException) {
                }
            }
        }
        self::assertStringNotContainsString('SECRET', json_encode($result->identity()));
        self::assertFalse(method_exists($result->identity(), 'accessToken'));
        self::assertFalse(method_exists($result->credentialContext(), 'refreshToken'));
        self::assertFalse(method_exists($this->f->client, 'checkApplicationAccess'));
    }

    public static function malformedRefresh(): array
    {
        return [[null], [''], [42], [[]]];
    }

    #[DataProvider('malformedRefresh')]
    public function test_managed_exchange_rejects_malformed_optional_refresh_token(mixed $value): void
    {
        $this->f->begin();
        $h = $this->f->login;
        $h->http->responses[Harness::TOKEN] = ['access_token' => 'ACCESS', 'id_token' => $h->jwt(), 'token_type' => 'Bearer', 'expires_in' => 900, 'refresh_token' => $value];
        $this->expectException(DoxaException::class);
        $this->f->result();
    }

    public function test_absent_refresh_token_is_supported_without_inventing_one(): void
    {
        $this->f->begin();
        $h = $this->f->login;
        $h->http->responses[Harness::TOKEN] = ['access_token' => 'ACCESS', 'id_token' => $h->jwt(), 'token_type' => 'Bearer', 'expires_in' => 900];
        $result = $this->f->result();
        self::assertNull($this->f->payload($result->credentialContext()->serverReference())['refresh_token']);
    }

    public function test_ambient_database_transaction_cannot_report_durable_success(): void
    {
        $this->f->db->beginTransaction();
        try {
            $this->f->begin();
            self::fail('Ambient transaction accepted');
        } catch (CredentialException) {
            self::assertSame([], $this->f->login->http->requests);
        } finally {
            $this->f->db->rollBack();
        }
    }

    public function test_completed_context_deletion_remains_idempotent_after_session_regeneration(): void
    {
        $this->f->begin();
        $context = $this->f->result()->credentialContext();
        $this->f->login->session->setId(str_repeat('b', 40));
        $this->f->host->allowRegeneration = true;
        $context = $this->f->client->bindCredentialContext($context);
        $this->f->client->deleteCredentialContext($context);
        $this->f->client->deleteCredentialContext($context);
        self::assertSame('deleted', $this->f->store->metadata($context->recordId())['status']);
    }

    public function test_lost_binding_commit_response_invalidates_the_context(): void
    {
        $this->f->configure(new FaultCredentialStore($this->f->store, 'binding_commit'));
        $this->f->begin();
        $context = $this->f->result()->credentialContext();
        try {
            $context = $this->f->client->bindCredentialContext($context);
            self::fail('Uncertain binding returned success');
        } catch (CredentialException) {
            self::assertSame('unusable', $this->f->store->metadata($context->recordId())['status']);
            self::assertArrayNotHasKey('access_token', $this->f->payload($context->recordId()));
        }
    }

    public function test_pending_link_cancellation_and_expiry_are_terminal(): void
    {
        $this->f->begin(ManagedLoginPolicy::pendingLink('link-1'));
        $context = $this->f->result()->credentialContext();
        $this->f->mutate($context->serverReference(), ['expires_at' => time() - 1]);
        self::assertSame(1, $this->f->store->cleanup());
        self::assertSame('expired', $this->f->store->metadata($context->recordId())['status']);
        $this->expectException(CredentialException::class);
        $this->f->client->restoreCredentialContext($context->serverReference());
    }

    public function test_pending_link_live_host_revocation_is_rechecked_before_exchange(): void
    {
        $this->f->begin(ManagedLoginPolicy::pendingLink('link-1'));
        $this->f->host->live = false;
        try {
            $this->f->result();
            self::fail('Cancelled host transaction accepted');
        } catch (CredentialException) {
            self::assertSame(0, $this->f->login->http->count(Harness::TOKEN));
        }
    }

    public function test_managed_state_nonce_and_provenance_remain_enforced(): void
    {
        $this->f->begin();
        $h = $this->f->login;
        try {
            $this->f->client->handleManagedCallback($h->request('state='.str_repeat('z', 43).'&code=CODE'));
            self::fail('Foreign state accepted');
        } catch (DoxaException) {
            self::assertSame(0, $h->http->count(Harness::TOKEN));
        }
        $h->claimChanges['nonce'] = 'wrong';
        try {
            $this->f->result();
            self::fail('Wrong nonce accepted');
        } catch (DoxaException) {
            self::assertSame(0, $this->f->db->table($this->f->table)->count());
        }
    }

    public function test_store_does_not_emit_sql_events_or_retain_query_bindings(): void
    {
        $events = new Dispatcher;
        $events->listen(QueryExecuted::class, function () {
            self::fail('Credential query emitted a host SQL event');
        });
        $this->f->db->setEventDispatcher($events);
        $this->f->db->enableQueryLog();
        $isolated = new DatabaseCredentialStore($this->f->db, $this->f->cipher, $this->f->table);
        $this->f->configure($isolated);
        try {
            $this->f->begin();
            $result = $this->f->result();
            $this->f->client->deleteCredentialContext($result->credentialContext());
            self::assertSame([], $this->f->db->getQueryLog());
        } finally {
            $this->f->db->unsetEventDispatcher();
        }
    }

    public function test_callback_policy_preserves_authorization_options_and_managed_binding(): void
    {
        $h = $this->f->login;
        $response = $this->f->client->beginManagedLogin(ManagedLoginPolicy::ordinary(), AuthorizationOptions::reauthenticate());
        parse_str(parse_url($response->getTargetUrl(), PHP_URL_QUERY), $h->query);
        self::assertSame('login', $h->query['prompt']);
        self::assertSame('S256', $h->query['code_challenge_method']);
        self::assertSame('ordinary', $h->transaction()->managedPolicy()['purpose']);
        self::assertSame('public-subject', $this->f->result()->identity()->subject());
    }

    public function test_ac26_staging_rejects_identity_from_a_different_validated_token(): void
    {
        $this->f->begin();
        $h = $this->f->login;
        $transaction = $h->store->claim($h->query['state'], hash('sha256', $h->session->getId()));
        $tokens = (new CodeExchange($h->config, $h->http, $h->store))->exchange('CODE', $transaction);
        $foreign = DoxaIdentity::authenticate($h->validator, $h->jwt(['sub' => 'foreign-subject']), $transaction);
        try {
            $this->f->manager->stage($tokens, $foreign, $transaction, $h->request(), $h->validator);
            self::fail('Identity/credential substitution accepted');
        } catch (DoxaException) {
            self::assertSame(0, $this->f->db->table($this->f->table)->count());
        }
    }

    public static function explicitNullFields(): array
    {
        return [['scope'], ['error']];
    }

    #[DataProvider('explicitNullFields')]
    public function test_explicit_null_response_fields_are_not_valid_omission(string $field): void
    {
        $this->f->begin();
        $h = $this->f->login;
        $h->http->responses[Harness::TOKEN] = ['access_token' => 'ACCESS', 'id_token' => $h->jwt(), 'token_type' => 'Bearer', 'expires_in' => 900, $field => null];
        try {
            $this->f->result();
            self::fail('Malformed managed response accepted');
        } catch (DoxaException) {
            self::assertSame(0, $this->f->db->table($this->f->table)->count());
        }
    }

    public function test_cleanup_batch_is_not_starved_by_tombstones_not_yet_due_for_purge(): void
    {
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $this->f->begin();
            $ids[] = $this->f->result()->credentialContext()->serverReference();
        }
        sort($ids);
        $this->f->store->delete($ids[0], 2);
        foreach ($ids as $id) {
            $this->f->mutate($id, ['expires_at' => time() - 1]);
        }
        self::assertSame(1, $this->f->store->cleanup(1));
        self::assertSame('expired', $this->f->store->metadata($ids[1])['status']);
    }

    public function test_process_local_memory_database_cannot_be_a_credential_store(): void
    {
        $capsule = new Manager;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->expectException(CredentialException::class);
        new DatabaseCredentialStore($capsule->getConnection(), $this->f->cipher);
    }
}
