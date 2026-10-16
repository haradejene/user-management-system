<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Integration;

use Doxa\Laravel\Credentials\CredentialContext;
use Doxa\Laravel\Credentials\DatabaseCredentialStore;
use Doxa\Laravel\Credentials\ManagedLoginPolicy;
use Doxa\Laravel\Credentials\RefreshAttempt;
use Doxa\Laravel\Exceptions\CredentialException;
use Doxa\Laravel\Tests\Fixtures\CredentialFixture;
use Doxa\Laravel\Tests\Fixtures\RefreshLostAcknowledgement;
use Doxa\Laravel\Tests\Fixtures\RefreshUpdateBarrier;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RefreshCoordinationTest extends TestCase
{
    private CredentialFixture $f;

    private CredentialContext $context;

    protected function setUp(): void
    {
        $this->f = new CredentialFixture;
        $this->f->begin();
        $this->context = $this->f->client->bindCredentialContext($this->f->result()->credentialContext());
        $this->f->mutate($this->context->recordId(), ['access_expires_at' => time() + 20]);
    }

    protected function tearDown(): void
    {
        $this->f->cleanup();
    }

    private function pair(): array
    {
        return ['access_token' => 'ROTATED-ACCESS', 'refresh_token' => 'ROTATED-REFRESH',
            'access_expires_at' => time() + 900, 'refresh_expires_at' => null, 'scopes' => ['openid']];
    }

    private function claim(): RefreshAttempt
    {
        return $this->f->manager->claimRefresh($this->context, $this->f->login->request());
    }

    private function dispatch(RefreshAttempt $attempt): RefreshAttempt
    {
        return $this->f->manager->dispatchRefresh($this->context, $this->f->login->request(), $attempt);
    }

    private function rejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Unsafe transition succeeded');
        } catch (CredentialException $error) {
            self::assertNull($error->getPrevious());
        }
    }

    public function test_pair_replacement_is_atomic_and_preserves_ownership_and_absolute_lifetime(): void
    {
        $before = $this->f->payload($this->context->recordId());
        $claim = $this->claim();
        self::assertSame('refreshing', $this->f->store->metadata($this->context->recordId())['status']);
        $this->rejected(fn () => $this->f->client->restoreCredentialContext($this->context->serverReference()));
        $attempt = $this->dispatch($claim);
        $pair = $this->pair();
        $this->f->manager->completeRefresh($this->context, $this->f->login->request(), $attempt, $pair);
        $after = $this->f->payload($this->context->recordId());
        foreach ($pair as $field => $value) {
            self::assertSame($value, $after[$field]);
        }
        foreach (['issuer', 'client', 'subject', 'owner', 'binding', 'purpose', 'absolute_expires_at', 'expires_at'] as $field) {
            self::assertSame($before[$field], $after[$field]);
        }
        self::assertSame($before['version'] + 3, $after['version']);
        self::assertSame($this->context->serverReference(), $this->f->client->restoreCredentialContext($this->context->serverReference())->serverReference());
        self::assertArrayNotHasKey('access_token', $this->f->store->metadata($this->context->recordId()));
        self::assertArrayNotHasKey('refresh_token', $this->f->store->metadata($this->context->recordId()));
        // Same-response reconciliation is read-only and never dispatches again.
        $this->f->manager->completeRefresh($this->context, $this->f->login->request(), $attempt, $pair);
        self::assertSame($after['version'], $this->f->store->metadata($this->context->recordId())['version']);
        self::assertSame(1, $this->f->login->http->count('https://iam.example.test/oauth/token'));
    }

    public function test_pre_dispatch_release_fences_the_old_attempt_and_later_success_survives_stale_work(): void
    {
        $old = $this->claim();
        $this->f->manager->releaseRefresh($this->context, $this->f->login->request(), $old);
        $new = $this->dispatch($this->claim());
        $this->f->manager->completeRefresh($this->context, $this->f->login->request(), $new, $this->pair());
        $version = $this->f->store->metadata($this->context->recordId())['version'];
        $this->rejected(fn () => $this->dispatch($old));
        $this->rejected(fn () => $this->f->manager->releaseRefresh($this->context, $this->f->login->request(), $old));
        $this->rejected(fn () => $this->f->manager->failRefresh($this->context, $this->f->login->request(), $old));
        self::assertSame($version, $this->f->store->metadata($this->context->recordId())['version']);
        self::assertSame('active', $this->f->store->metadata($this->context->recordId())['status']);
    }

    public function test_duplicate_and_out_of_order_operations_do_not_redispatch_or_restore_old_pair(): void
    {
        $claim = $this->claim();
        $this->rejected(fn () => $this->claim());
        $this->rejected(fn () => $this->f->manager->completeRefresh($this->context, $this->f->login->request(), $claim, $this->pair()));
        $attempt = $this->dispatch($claim);
        $this->rejected(fn () => $this->dispatch($claim));
        $this->rejected(fn () => $this->f->manager->releaseRefresh($this->context, $this->f->login->request(), $attempt));
        $this->f->manager->failRefresh($this->context, $this->f->login->request(), $attempt);
        $payload = $this->f->payload($this->context->recordId());
        self::assertSame('unusable', $payload['status']);
        self::assertSame('refresh_outcome_uncertain', $payload['reason']);
        self::assertArrayNotHasKey('access_token', $payload);
        self::assertArrayNotHasKey('refresh_token', $payload);
        $this->rejected(fn () => $this->f->manager->completeRefresh($this->context, $this->f->login->request(), $attempt, $this->pair()));
        $this->rejected(fn () => $this->claim());
    }

    public static function substitutions(): array
    {
        return array_map(fn ($field) => [$field], ['proof', 'session', 'owner', 'issuer', 'client', 'subject', 'purpose']);
    }

    #[DataProvider('substitutions')]
    public function test_owner_and_completed_proof_remain_required_during_refresh(string $field): void
    {
        $claim = $this->claim();
        if ($field === 'proof') {
            $this->context = CredentialContext::fromServerReference($this->context->recordId());
        } elseif ($field === 'session') {
            $this->f->login->session->setId(str_repeat('z', 40));
        } elseif ($field === 'owner') {
            $this->f->host->account = 'foreign';
        } else {
            $this->f->mutate($this->context->recordId(), [$field => 'foreign']);
        }
        $this->rejected(fn () => $this->dispatch($claim));
        self::assertSame('claimed', $this->f->store->metadata($this->context->recordId())['refresh']['phase']);
    }

    public function test_wrong_attempt_context_version_and_unexpected_state_are_rejected(): void
    {
        $claim = $this->claim();
        foreach ([new RefreshAttempt(str_repeat('0', 64), $claim->id, $claim->version, 'claimed'),
            new RefreshAttempt($claim->contextId, str_repeat('0', 64), $claim->version, 'claimed'),
            new RefreshAttempt($claim->contextId, $claim->id, $claim->version + 1, 'claimed')] as $forged) {
            $this->rejected(fn () => $this->dispatch($forged));
        }
        $this->f->mutate($this->context->recordId(), ['version' => $claim->version + 1]);
        $this->rejected(fn () => $this->dispatch($claim));
        $this->f->mutate($this->context->recordId(), ['version' => $claim->version, 'status' => 'unusable']);
        $this->rejected(fn () => $this->dispatch($claim));
    }

    public function test_pending_and_ordinary_unbound_contexts_cannot_enter_refresh(): void
    {
        foreach ([ManagedLoginPolicy::ordinary(), ManagedLoginPolicy::pendingLink('link-1')] as $policy) {
            $this->f->begin($policy);
            $context = $this->f->result()->credentialContext();
            $this->rejected(fn () => $this->f->manager->claimRefresh($context, $this->f->login->request()));
        }
    }

    public static function malformedPairs(): array
    {
        return [['refresh_token', 'REFRESH-SECRET'], ['refresh_token', ''], ['access_token', ''],
            ['access_expires_at', 0], ['scopes', ['openid', 'offline_access']], ['scopes', ['openid', 4]]];
    }

    #[DataProvider('malformedPairs')]
    public function test_invalid_replacement_retires_only_its_dispatched_attempt(string $field, mixed $value): void
    {
        $attempt = $this->dispatch($this->claim());
        $this->rejected(fn () => $this->f->manager->completeRefresh($this->context, $this->f->login->request(), $attempt, [...$this->pair(), $field => $value]));
        $payload = $this->f->payload($this->context->recordId());
        self::assertSame('unusable', $payload['status']);
        self::assertArrayNotHasKey('refresh_token', $payload);
    }

    public function test_lost_update_acknowledgements_leave_durable_phase_and_exact_response_reconciliation(): void
    {
        $config = $this->f->db->getConfig();
        $db = $this->f->db->getDriverName() === 'pgsql'
            ? new class($this->f->db->getPdo(), $this->f->db->getDatabaseName(), '', $config) extends PostgresConnection
            {
                use RefreshLostAcknowledgement;
            }
        : new class($this->f->db->getPdo(), $this->f->db->getDatabaseName(), '', $config) extends SQLiteConnection
        {
            use RefreshLostAcknowledgement;
        };
        $db->fault = (object) ['armed' => false];
        $this->f->configure(new DatabaseCredentialStore($db, $this->f->cipher, $this->f->table));
        $db->fault->armed = true;
        $this->rejected(fn () => $this->claim());
        self::assertSame('claimed', $this->f->store->metadata($this->context->recordId())['refresh']['phase']);
        $this->rejected(fn () => $this->f->client->restoreCredentialContext($this->context->serverReference()));
        $this->expireAttempt();
        $this->f->store->cleanup();
        $claim = $this->claim();
        $db->fault->armed = true;
        $db->fault->outageAfterWrite = true;
        $this->rejected(fn () => $this->dispatch($claim));
        self::assertSame('dispatched', $this->f->store->metadata($this->context->recordId())['refresh']['phase']);
        $this->rejected(fn () => $this->f->client->restoreCredentialContext($this->context->serverReference()));
        $this->rejected(fn () => $this->f->manager->releaseRefresh($this->context, $this->f->login->request(), $claim));
        $db->fault->unavailable = false;
        $this->rejected(fn () => $this->dispatch($claim));
        $this->expireAttempt();
        $this->f->store->cleanup();
        self::assertSame('unusable', $this->f->store->metadata($this->context->recordId())['status']);
    }

    private function expireAttempt(): void
    {
        $this->f->mutate($this->context->recordId(), ['expires_at' => time() - 1]);
    }

    public function test_lost_completion_acknowledgement_reconciles_only_the_same_response_and_attempt(): void
    {
        $attempt = $this->dispatch($this->claim());
        $config = $this->f->db->getConfig();
        $db = $this->f->db->getDriverName() === 'pgsql'
            ? new class($this->f->db->getPdo(), $this->f->db->getDatabaseName(), '', $config) extends PostgresConnection
            {
                use RefreshLostAcknowledgement;
            }
        : new class($this->f->db->getPdo(), $this->f->db->getDatabaseName(), '', $config) extends SQLiteConnection
        {
            use RefreshLostAcknowledgement;
        };
        $db->fault = (object) ['armed' => true];
        $this->f->configure(new DatabaseCredentialStore($db, $this->f->cipher, $this->f->table));
        $pair = $this->pair();
        $this->rejected(fn () => $this->f->manager->completeRefresh($this->context, $this->f->login->request(), $attempt, $pair));
        $payload = $this->f->payload($this->context->recordId());
        self::assertSame('active', $payload['status']);
        self::assertSame('completed', $payload['refresh']['phase']);
        self::assertSame('ROTATED-REFRESH', $payload['refresh_token']);
        $this->f->configure();
        $this->f->manager->completeRefresh($this->context, $this->f->login->request(), $attempt, $pair);
        $this->rejected(fn () => $this->f->manager->completeRefresh($this->context, $this->f->login->request(), $attempt, [...$pair, 'refresh_token' => 'OTHER']));
        self::assertSame($payload, $this->f->payload($this->context->recordId()));
        $this->f->db->beginTransaction();
        try {
            $this->rejected(fn () => $this->f->manager->completeRefresh($this->context, $this->f->login->request(), $attempt, $pair));
        } finally {
            $this->f->db->rollBack();
        }
    }

    public function test_old_completed_attempt_cannot_overwrite_a_later_rotated_pair(): void
    {
        $old = $this->dispatch($this->claim());
        $pair = $this->pair();
        $this->f->manager->completeRefresh($this->context, $this->f->login->request(), $old, $pair);
        $this->f->mutate($this->context->recordId(), ['access_expires_at' => time() + 20]);
        $new = $this->dispatch($this->claim());
        $newPair = [...$pair, 'access_token' => 'SECOND-ACCESS', 'refresh_token' => 'SECOND-REFRESH'];
        $this->f->manager->completeRefresh($this->context, $this->f->login->request(), $new, $newPair);
        $payload = $this->f->payload($this->context->recordId());
        $this->rejected(fn () => $this->f->manager->completeRefresh($this->context, $this->f->login->request(), $old, $pair));
        $this->rejected(fn () => $this->f->manager->failRefresh($this->context, $this->f->login->request(), $old));
        self::assertSame($payload, $this->f->payload($this->context->recordId()));
    }

    public function test_absolute_expiry_and_deletion_prevent_release_or_replacement(): void
    {
        $claim = $this->claim();
        $this->f->mutate($this->context->recordId(), ['absolute_expires_at' => time() - 1]);
        $this->rejected(fn () => $this->f->manager->releaseRefresh($this->context, $this->f->login->request(), $claim));
        $this->expireAttempt();
        $this->f->store->cleanup();
        self::assertSame('expired', $this->f->store->metadata($this->context->recordId())['status']);
        self::assertArrayNotHasKey('refresh_token', $this->f->payload($this->context->recordId()));
    }

    public function test_still_usable_access_or_missing_refresh_cannot_be_claimed_and_attempts_are_protected(): void
    {
        $this->f->mutate($this->context->recordId(), ['access_expires_at' => time() + 900]);
        $this->rejected(fn () => $this->claim());
        $this->f->mutate($this->context->recordId(), ['access_expires_at' => time() + 20, 'refresh_token' => null]);
        $this->rejected(fn () => $this->claim());
        $this->f->mutate($this->context->recordId(), ['refresh_token' => 'REFRESH-SECRET']);
        $attempt = $this->claim();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $attempt->id);
        self::assertStringNotContainsString($attempt->id, print_r($attempt, true));
        $this->expectException(\LogicException::class);
        serialize($attempt);
    }

    public static function races(): array
    {
        return [['claim', 'claim'], ['claim', 'delete'], ['complete', 'delete'], ['complete', 'cleanup']];
    }

    #[DataProvider('races')]
    public function test_independent_workers_cannot_double_claim_or_revive_terminal_context(string $one, string $two): void
    {
        $attempt = $one === 'complete' ? $this->dispatch($this->claim()) : null;
        if ($two === 'cleanup') {
            $this->expireAttempt();
        }
        $results = $this->workers([$one, $two], $attempt);
        $payload = $this->f->payload($this->context->recordId());
        if ($two === 'claim') {
            self::assertSame(1, count(array_filter($results, fn ($r) => $r === 'success')));
            self::assertSame('refreshing', $payload['status']);
        } else {
            self::assertContains($payload['status'], ['deleted', 'unusable']);
            self::assertArrayNotHasKey('refresh_token', $payload);
        }
    }

    public function test_cleanup_after_live_completion_validation_fences_the_actual_conditional_update(): void
    {
        $attempt = $this->dispatch($this->claim());
        $live = $this->f->payload($this->context->recordId());
        $driver = $this->f->db->getDriverName();
        self::assertSame(getenv('DOXA_CREDENTIAL_TEST_DRIVER') === 'pgsql' ? 'pgsql' : 'sqlite', $driver);
        $capsule = new Manager;
        $capsule->addConnection($this->f->db->getConfig());
        $connection = $capsule->getConnection();
        self::assertSame($driver, $connection->getDriverName());
        self::assertNotSame($this->f->db->getPdo(), $connection->getPdo());
        self::assertFalse($connection->getPdo()->inTransaction());
        $db = $driver === 'pgsql'
            ? new class($connection->getPdo(), $connection->getDatabaseName(), '', $connection->getConfig()) extends PostgresConnection
            {
                use RefreshUpdateBarrier;
            }
        : new class($connection->getPdo(), $connection->getDatabaseName(), '', $connection->getConfig()) extends SQLiteConnection
        {
            use RefreshUpdateBarrier;
        };
        $terminal = null;
        $terminalRow = null;
        $db->barrier = (object) ['reached' => false, 'affected' => null,
            'before' => function () use ($live, $attempt, &$terminal, &$terminalRow): void {
                // Completion has passed owner/proof/attempt/expiry validation and compiled its SQL.
                self::assertSame($live, $this->f->payload($this->context->recordId()));
                self::assertSame('refreshing', $live['status']);
                self::assertSame('dispatched', $live['refresh']['phase']);
                self::assertSame($attempt->version, $live['version']);
                self::assertGreaterThan(time(), $live['expires_at']);
                self::assertTrue($this->context->confirms($live));
                self::assertFalse($this->f->db->getPdo()->inTransaction());
                // Deterministic fixture clock advance, not a sleep or an invented lifecycle transition.
                $this->expireAttempt();
                self::assertSame(1, $this->f->store->cleanup());
                $terminal = $this->f->payload($this->context->recordId());
                $terminalRow = (array) $this->f->db->table($this->f->table)->where('id', $this->context->recordId())->first();
                self::assertSame('unusable', $terminal['status']);
                self::assertSame('refresh_outcome_uncertain', $terminal['reason']);
                self::assertSame($attempt->version + 1, $terminal['version']);
                self::assertArrayNotHasKey('access_token', $terminal);
                self::assertArrayNotHasKey('refresh_token', $terminal);
            }];
        $barrier = $db->barrier;
        $store = new DatabaseCredentialStore($db, $this->f->cipher, $this->f->table);
        $this->f->configure($store);
        // A distinct, permitted store-level scope subset makes accidental scope replacement visible.
        $replacement = [...$this->pair(), 'scopes' => []];
        try {
            try {
                $this->f->manager->completeRefresh($this->context, $this->f->login->request(), $attempt, $replacement);
                self::fail('Stale completion revived a cleaned context');
            } catch (CredentialException $error) {
                self::assertSame('credential_lifecycle_failure', $error->category);
            }
            self::assertTrue($barrier->reached);
            // Proves the real conditional UPDATE ran after cleanup; not an early expiry rejection.
            self::assertSame(0, $barrier->affected);
            self::assertNotNull($terminal);
            self::assertNotNull($terminalRow);
            self::assertSame($terminal, $this->f->payload($this->context->recordId()));
            self::assertSame($terminalRow, (array) $this->f->db->table($this->f->table)->where('id', $this->context->recordId())->first());
            self::assertSame($live['scopes'], $terminal['scopes']);
            self::assertNotSame($replacement['scopes'], $terminal['scopes']);
            self::assertSame($live['access_expires_at'], $terminal['access_expires_at']);
            self::assertSame($live['absolute_expires_at'], $terminal['absolute_expires_at']);
            $this->rejected(fn () => $this->f->manager->completeRefresh($this->context, $this->f->login->request(), $attempt, $replacement));
            $this->rejected(fn () => $this->f->client->restoreCredentialContext($this->context->serverReference()));
            self::assertSame($terminal, $this->f->payload($this->context->recordId()));
        } finally {
            $this->f->configure();
            unset($store, $db);
            $connection->disconnect();
        }
    }

    public static function crashPhases(): array
    {
        return [['crash_claim', 'active'], ['crash_dispatch', 'unusable']];
    }

    #[DataProvider('crashPhases')]
    public function test_process_death_recovery_distinguishes_proven_non_dispatch_from_possible_dispatch(string $operation, string $status): void
    {
        self::assertSame(['success'], $this->workers([$operation]));
        self::assertSame('refreshing', $this->f->store->metadata($this->context->recordId())['status']);
        $this->f->configure();
        $this->rejected(fn () => $this->claim());
        $this->rejected(fn () => $this->f->client->restoreCredentialContext($this->context->serverReference()));
        $this->expireAttempt();
        self::assertSame(1, $this->f->store->cleanup());
        self::assertSame($status, $this->f->store->metadata($this->context->recordId())['status']);
        if ($status === 'active') {
            self::assertSame('claimed', $this->claim()->phase);
        } else {
            self::assertArrayNotHasKey('refresh_token', $this->f->payload($this->context->recordId()));
            $this->rejected(fn () => $this->claim());
        }
    }

    private function workers(array $operations, ?RefreshAttempt $attempt = null): array
    {
        $directory = $this->f->login->directory;
        $config = $this->f->db->getConfig();
        unset($config['password']);
        file_put_contents($directory.'/refresh-input.json', json_encode(['connection' => $config, 'table' => $this->f->table,
            'reference' => $this->context->serverReference(), 'attempt' => $attempt === null ? null : [
                $attempt->contextId, $attempt->id, $attempt->version, $attempt->phase], 'pair' => $this->pair()]));
        $workers = [];
        foreach ($operations as $i => $operation) {
            $worker = new Process([PHP_BINARY, dirname(__DIR__).'/Concurrency/refresh-worker.php', $directory, (string) $i, $operation], timeout: 35);
            $worker->start();
            $workers[] = $worker;
        }
        $deadline = microtime(true) + 25;
        foreach ($workers as $i => $worker) {
            while (! is_file($directory.'/refresh-ready-'.$i)) {
                if (microtime(true) > $deadline) {
                    self::fail('Refresh worker readiness timeout: '.$worker->getErrorOutput());
                }
                usleep(10000);
            }
        }
        file_put_contents($directory.'/refresh-release', 'release');
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $results[] = $worker->getOutput();
        }

        return $results;
    }
}
