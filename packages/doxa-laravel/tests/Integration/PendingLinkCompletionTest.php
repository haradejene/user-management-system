<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Integration;

use Doxa\Laravel\Credentials\CredentialContext;
use Doxa\Laravel\Credentials\DatabaseCredentialStore;
use Doxa\Laravel\Credentials\ManagedLoginPolicy;
use Doxa\Laravel\Exceptions\CredentialException;
use Doxa\Laravel\Tests\Fixtures\CredentialFixture;
use Doxa\Laravel\Tests\Fixtures\ManagedWriteFault;
use Doxa\Laravel\Tests\Fixtures\PendingLinkHost;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Process;

final class PendingLinkCompletionTest extends TestCase
{
    private CredentialFixture $f;

    private PendingLinkHost $host;

    private CredentialContext $pending;

    private array $requests = [];

    private array $responses = [];

    private DatabaseCredentialStore $store;

    protected function setUp(): void
    {
        $this->f = new CredentialFixture;
        $this->host = new PendingLinkHost($this->f->db, $this->f->table.'_host', $this->f->host);
        $this->install($this->f->db);
        $this->f->begin(ManagedLoginPolicy::pendingLink('link-1'));
        $this->pending = $this->f->result()->credentialContext();
    }

    private function install($db): void
    {
        $this->store = new DatabaseCredentialStore($db, $this->f->cipher, $this->f->table, 60,
            function (#[\SensitiveParameter] RequestInterface $request, #[\SensitiveParameter] array $options) {
                $this->requests[] = $request->getMethod();
                $next = array_shift($this->responses);
                if ($next instanceof \Closure) {
                    $next = $next();
                }

                return $next instanceof \Throwable ? Create::rejectionFor($next)
                    : Create::promiseFor($next ?? new Response(200, ['Content-Type' => 'application/json'], '{"effective":true}'));
            });
        $this->f->configure($this->store, null, $this->host);
    }

    protected function tearDown(): void
    {
        $this->f->db->getSchemaBuilder()->drop($this->host->table);
        $this->f->cleanup();
        unset($this->f, $this->host, $this->store);
        gc_collect_cycles();
    }

    private function failure(): void
    {
        try {
            $this->f->client->completePendingLink($this->pending);
            self::fail('Unsafe completion');
        } catch (CredentialException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('SECRET_SENTINEL', $error->getMessage());
        }
    }

    private function erased(): void
    {
        $record = $this->f->payload($this->pending->recordId());
        self::assertContains($record['status'], ['unusable', 'deleted', 'expired']);
        self::assertArrayNotHasKey('access_token', $record);
        self::assertArrayNotHasKey('refresh_token', $record);
        self::assertNull($this->f->db->table($this->host->table)->first()->mapping);
    }

    public function test_promotion_requires_fresh_access_and_committed_exact_mapping_and_replacement_proof(): void
    {
        $before = $this->f->payload($this->pending->recordId());
        // A prior check is NOT reused as confirmation.
        self::assertTrue($this->f->client->checkApplicationAccess($this->pending)->effective);
        $this->host->beforeCommit = function (): void {
            $record = $this->f->payload($this->pending->recordId());
            self::assertSame('link_binding', $record['status']);
            self::assertNull($record['owner']);
            try {
                $this->f->client->restoreCredentialContext($this->pending->serverReference());
                self::fail('Staged completion restored');
            } catch (CredentialException) {
            }
        };
        $completed = $this->f->client->completePendingLink($this->pending);
        self::assertSame(['GET', 'GET'], $this->requests);
        $after = $this->f->payload($completed->recordId());
        self::assertSame('ordinary', $after['purpose']);
        self::assertSame('active', $after['status']);
        self::assertSame(['account' => 'local-7', 'session' => $before['policy']['browser']], $after['owner']);
        foreach (['issuer', 'client', 'subject', 'absolute_expires_at', 'expires_at', 'scopes', 'scope_ceiling'] as $field) {
            self::assertSame($before[$field], $after[$field]);
        }
        self::assertNotSame($this->pending->serverReference(), $completed->serverReference());
        self::assertTrue($completed->confirms($after));
        self::assertSame($completed->serverReference(), $this->f->client->restoreCredentialContext($completed->serverReference())->serverReference());
        self::assertTrue($this->f->client->checkApplicationAccess($completed)->effective);
        $this->failure(); // Original pending handle cannot be replayed or transferred.
        self::assertSame('active', $this->f->payload($completed->recordId())['status']);
        $this->f->host->account = 'another-owner';
        $this->expectException(CredentialException::class);
        $this->f->client->restoreCredentialContext($completed->serverReference());
    }

    public function test_missing_confirmation_consumes_attempt_without_iam_or_mapping(): void
    {
        $this->f->db->table($this->host->table)->update(['confirmed' => false]);
        $this->failure();
        self::assertSame([], $this->requests);
        $this->erased();
        self::assertSame('aborted', $this->f->db->table($this->host->table)->first()->state);
        $this->failure();
        self::assertSame([], $this->requests);
    }

    #[DataProvider('denials')]
    public function test_no_mapping_on_unavailable_denied_invalid_or_malformed_fresh_access(int $status, string $body): void
    {
        $this->responses[] = new Response($status, ['Content-Type' => 'application/json'], $body);
        $this->failure();
        self::assertSame(['GET'], $this->requests);
        $this->erased();
    }

    public static function denials(): array
    {
        return [[401, '{}'], [403, '{}'], [500, '{}'], [200, '{"effective":false}'], [302, '{}']];
    }

    #[DataProvider('substitutions')]
    public function test_wrong_trusted_bindings_cannot_claim_or_send(string $field): void
    {
        if (in_array($field, ['initiator', 'link', 'target', 'account'], true)) {
            $this->f->host->$field = 'wrong';
        } elseif ($field === 'session') {
            $this->f->login->session->setId(str_repeat('x', 40));
        } else {
            $this->f->mutate($this->pending->recordId(), [$field => 'wrong']);
        }
        $this->failure();
        self::assertSame([], $this->requests);
        self::assertNull($this->f->db->table($this->host->table)->first()->attempt);
    }

    public static function substitutions(): array
    {
        return array_map(static fn ($field) => [$field], ['issuer', 'client', 'subject', 'purpose', 'initiator', 'link', 'target', 'account', 'session']);
    }

    public function test_expiry_and_cancellation_cannot_promote(): void
    {
        $this->responses[] = function () {
            $this->f->client->deleteCredentialContext($this->pending);

            return new Response(200, ['Content-Type' => 'application/json'], '{"effective":true}');
        };
        $this->failure();
        $this->erased();
    }

    public function test_expiry_after_fresh_check_prevents_final_binding_and_compensates_mapping(): void
    {
        $this->host->afterCommit = function (): void {
            $this->f->mutate($this->pending->recordId(), ['expires_at' => time() - 1]);
        };
        $this->failure();
        $this->erased();
    }

    public function test_local_commit_ack_loss_uses_read_only_proof_not_second_mapping(): void
    {
        $this->host->loseCommitAck = true;
        $completed = $this->f->client->completePendingLink($this->pending);
        self::assertSame('committed', $this->f->db->table($this->host->table)->first()->state);
        self::assertTrue($completed->confirms($this->f->payload($completed->recordId())));
        self::assertSame(['GET'], $this->requests);
    }

    public function test_uncertain_host_commit_destroys_context_and_consumes_local_attempt(): void
    {
        $this->host->loseCommitAck = true;
        $this->host->cannotVerify = true;
        $this->failure();
        $this->erased();
        $this->host->cannotVerify = false;
        $this->failure();
        self::assertSame(['GET'], $this->requests);
    }

    #[DataProvider('writes')]
    public function test_real_write_acknowledgement_loss_is_exactly_fenced(int $target, bool $outage): void
    {
        $db = $this->f->db->getDriverName() === 'pgsql'
            ? new class($this->f->db->getPdo(), $this->f->db->getDatabaseName(), '', $this->f->db->getConfig()) extends PostgresConnection
            {
                use ManagedWriteFault;
            }
        : new class($this->f->db->getPdo(), $this->f->db->getDatabaseName(), '', $this->f->db->getConfig()) extends SQLiteConnection
        {
            use ManagedWriteFault;
        };
        $fault = $db->fault = (object) ['writes' => 0, 'target' => $target, 'outage' => $outage, 'unavailable' => false];
        $this->install($db);
        if ($target === 5 && ! $outage) {
            $completed = $this->f->client->completePendingLink($this->pending);
            self::assertTrue($completed->confirms($this->f->payload($completed->recordId())));
        } else {
            $this->failure();
            $fault->unavailable = false;
            // Process/storage recovery cannot manufacture the unreleased proof.
            try {
                $this->f->client->restoreCredentialContext($this->pending->serverReference());
                self::fail('Unresolved completion restored');
            } catch (CredentialException) {
            }
            $this->f->mutate($this->pending->recordId(), ['expires_at' => time() - 1]);
            $this->store->cleanup();
            $this->erased();
        }
    }

    public static function writes(): array
    {
        // claim, checking, release-check, stage-link, activate-link.
        return [[1, false], [1, true], [4, false], [4, true], [5, false], [5, true]];
    }

    public function test_reentrant_losing_confirmer_cannot_damage_winner(): void
    {
        $this->host->beforeCommit = function (): void {
            $this->failure();
            self::assertSame('link_binding', $this->f->payload($this->pending->recordId())['status']);
        };
        $completed = $this->f->client->completePendingLink($this->pending);
        self::assertTrue($completed->confirms($this->f->payload($completed->recordId())));
        self::assertSame(['GET'], $this->requests);
    }

    public function test_no_constructible_completion_ticket_or_existing_adapter_can_promote(): void
    {
        try {
            $this->store->completePendingLink($this->f->manager, new \stdClass);
            self::fail('Constructed proof accepted');
        } catch (CredentialException) {
        }
        $this->f->configure($this->store);
        $this->failure();
        self::assertSame('pending_link', $this->f->payload($this->pending->recordId())['status']);
        self::assertSame([], $this->requests);
    }

    public function test_independent_confirmers_race_before_real_claim_cas_without_damaging_winner(): void
    {
        $directory = $this->f->login->directory;
        $configuration = $this->f->db->getConfig();
        unset($configuration['password']);
        file_put_contents($directory.'/link-input.json', json_encode(['connection' => $configuration,
            'table' => $this->f->table, 'host_table' => $this->host->table, 'reference' => $this->pending->serverReference()]));
        $workers = [];
        try {
            foreach (['one', 'two'] as $name) {
                $worker = new Process([PHP_BINARY, dirname(__DIR__).'/Concurrency/pending-link-worker.php', $directory, $name], timeout: 35);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 25;
            while (! is_file($directory.'/link-ready-one') || ! is_file($directory.'/link-ready-two')) {
                if (microtime(true) > $deadline) {
                    self::fail('Independent confirmer barrier not reached');
                }
                usleep(10000);
            }
            file_put_contents($directory.'/link-release', 'release');
            $results = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = $worker->getOutput();
            }
            $successes = array_values(array_filter($results, static fn ($value) => str_contains($value, '.')));
            self::assertCount(1, $successes);
            self::assertContains('credential_binding_failure', $results);
            self::assertSame("check\n", file_get_contents($directory.'/link-checks'));
            $completed = $this->f->client->restoreCredentialContext($successes[0]);
            self::assertSame('active', $this->f->payload($completed->recordId())['status']);
            self::assertSame('committed', $this->f->db->table($this->host->table)->first()->state);
        } finally {
            file_put_contents($directory.'/link-release', 'release');
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function test_worker_death_after_host_commit_leaves_nonusable_stage_and_durable_abort_recovery(): void
    {
        $directory = $this->f->login->directory;
        $configuration = $this->f->db->getConfig();
        unset($configuration['password']);
        file_put_contents($directory.'/link-input.json', json_encode(['connection' => $configuration,
            'table' => $this->f->table, 'host_table' => $this->host->table, 'reference' => $this->pending->serverReference()]));
        file_put_contents($directory.'/link-release', 'release');
        $worker = new Process([PHP_BINARY, dirname(__DIR__).'/Concurrency/pending-link-worker.php', $directory, 'crash'], timeout: 35);
        self::assertSame(0, $worker->run(), $worker->getErrorOutput());
        self::assertSame('crashed-after-local-commit', $worker->getOutput());
        $record = $this->store->metadata($this->pending->recordId());
        self::assertSame('link_binding', $record['status']);
        self::assertNull($record['owner']);
        self::assertSame('committed', $this->f->db->table($this->host->table)->first()->state);
        $this->failure();
        // Host's durable recovery job cancels ONLY its own attempt; repeating it is harmless.
        $this->host->abortPendingLink($record, $record['completion']['attempt']);
        $this->host->abortPendingLink($record, $record['completion']['attempt']);
        $this->f->mutate($this->pending->recordId(), ['expires_at' => time() - 1]);
        $this->store->cleanup();
        $this->erased();
        $this->failure();
    }

    public function test_terminal_deletion_after_host_commit_cannot_be_revived_by_finalization(): void
    {
        $this->host->afterCommit = function (): void {
            $this->f->client->deleteCredentialContext($this->pending);
        };
        $this->failure();
        $this->erased();
    }

    public function test_completion_may_refresh_only_for_its_fresh_check_and_preserves_deadlines(): void
    {
        $this->f->mutate($this->pending->recordId(), ['access_expires_at' => time() + 10]);
        $before = $this->f->payload($this->pending->recordId());
        $this->responses[] = new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'NEW_ACCESS_SENTINEL', 'refresh_token' => 'NEW_REFRESH_SENTINEL', 'token_type' => 'Bearer', 'expires_in' => 900]));
        $completed = $this->f->client->completePendingLink($this->pending);
        self::assertSame(['POST', 'GET'], $this->requests);
        $after = $this->f->payload($completed->recordId());
        self::assertSame($before['expires_at'], $after['expires_at']);
        self::assertSame($before['absolute_expires_at'], $after['absolute_expires_at']);
        self::assertSame('NEW_REFRESH_SENTINEL', $after['refresh_token']);
        foreach (['NEW_ACCESS_SENTINEL', 'NEW_REFRESH_SENTINEL'] as $secret) {
            self::assertStringNotContainsString($secret, var_export($completed, true));
            self::assertStringNotContainsString($secret, print_r($completed, true));
        }
    }
}
