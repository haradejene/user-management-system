<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Integration;

use Doxa\Laravel\Credentials\CredentialSchema;
use Doxa\Laravel\Credentials\ManagedLoginPolicy;
use Doxa\Laravel\Exceptions\CredentialException;
use Doxa\Laravel\Tests\Fixtures\CredentialFixture;
use Doxa\Laravel\Tests\Fixtures\FaultCredentialStore;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BindingCompletionTest extends TestCase
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

    public function test_lost_acknowledgement_and_compensation_outage_cannot_restore_after_recovery(): void
    {
        $this->f->configure(new FaultCredentialStore($this->f->store, 'binding_outage'));
        $this->f->begin();
        $context = $this->f->result()->credentialContext();
        try {
            $this->f->client->bindCredentialContext($context);
            self::fail('Uncertain binding returned success');
        } catch (CredentialException $error) {
            self::assertSame('credential_binding_failure', $error->category);
        }
        self::assertSame('active', $this->f->store->metadata($context->recordId())['status']);
        $this->f->configure(); // Storage recovered; no process-local failure flag survives.
        $this->expectException(CredentialException::class);
        $this->f->client->restoreCredentialContext($context->serverReference());
    }

    public function test_failed_host_mapping_retires_only_its_durable_binding_attempt(): void
    {
        $this->f->begin();
        $context = $this->f->result()->credentialContext();
        $this->f->host->mappingCommitted = false;
        try {
            $this->f->client->bindCredentialContext($context);
            self::fail('Uncommitted mapping accepted');
        } catch (CredentialException) {
            $record = $this->f->store->metadata($context->recordId());
            self::assertSame('unusable', $record['status']);
            self::assertArrayNotHasKey('access_token', $this->f->payload($context->recordId()));
        }
        $this->f->host->mappingCommitted = true;
        $this->expectException(CredentialException::class);
        $this->f->client->restoreCredentialContext($context->serverReference());
    }

    public function test_completion_proof_and_attempt_are_immutable_and_stale_reconciliation_is_harmless(): void
    {
        $this->f->begin();
        $original = $this->f->result()->credentialContext();
        $completed = $this->f->client->bindCredentialContext($original);
        $record = $this->f->store->metadata($completed->recordId());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $record['binding']['attempt']);
        self::assertNotSame($original->serverReference(), $completed->serverReference());
        self::assertStringNotContainsString(explode('.', $completed->serverReference())[1], json_encode($record));
        self::assertStringNotContainsString($completed->serverReference(), print_r($completed, true));
        $this->f->store->abandonBinding($completed->recordId(), $record['version'], str_repeat('0', 64));
        $this->f->store->abandonBinding($completed->recordId(), $record['version'] - 1, $record['binding']['attempt']);
        self::assertSame('active', $this->f->store->metadata($completed->recordId())['status']);
        self::assertSame($completed->serverReference(), $this->f->client->restoreCredentialContext($completed->serverReference())->serverReference());
        foreach ([$original->serverReference(), $completed->recordId().'.'.str_repeat('0', 64)] as $reference) {
            try {
                $this->f->client->restoreCredentialContext($reference);
                self::fail('Missing/forged proof accepted');
            } catch (CredentialException) {
                self::assertSame('active', $this->f->store->metadata($completed->recordId())['status']);
            }
        }
        $this->f->host->account = 'another-owner';
        $this->expectException(CredentialException::class);
        $this->f->client->restoreCredentialContext($completed->serverReference());
    }

    public function test_schema_without_unique_provenance_fails_before_authorization(): void
    {
        $schema = $this->f->db->getSchemaBuilder();
        $schema->drop($this->f->table);
        $schema->create($this->f->table, function (Blueprint $row) {
            $row->string('id', 64)->primary();
            $row->string('provenance', 64);
            $row->string('status', 32)->index();
            $row->bigInteger('version');
            $row->bigInteger('expires_at')->index();
            $row->bigInteger('purge_at')->nullable()->index();
            $row->string('key_id', 64);
            $row->longText('payload');
        });
        try {
            $this->f->begin();
            self::fail('Incomplete schema accepted');
        } catch (CredentialException) {
            self::assertSame([], $this->f->login->http->requests);
        }
        $schema->drop($this->f->table);
        CredentialSchema::create($schema, $this->f->table);
        $this->f->store->ready();
        self::assertSame(302, $this->f->client->beginManagedLogin(ManagedLoginPolicy::ordinary())->getStatusCode());
    }

    public function test_nonnullable_purge_deadline_fails_preflight_before_authorization(): void
    {
        $schema = $this->f->db->getSchemaBuilder();
        // This helper is used verbatim by the published migration.
        $this->f->store->ready();
        self::assertSame(302, $this->f->client->beginManagedLogin(ManagedLoginPolicy::ordinary())->getStatusCode());
        $this->f->login->http->requests = [];
        $schema->drop($this->f->table);
        $schema->create($this->f->table, function (Blueprint $row) {
            $row->string('id', 64)->primary();
            $row->string('provenance', 64)->unique();
            $row->string('status', 32)->index();
            $row->bigInteger('version');
            $row->bigInteger('expires_at')->index();
            $row->bigInteger('purge_at')->index(); // Only difference: NOT NULL.
            $row->string('key_id', 64);
            $row->longText('payload');
        });
        try {
            $this->f->store->ready();
            self::fail('Nonnullable purge deadline accepted by readiness');
        } catch (CredentialException $error) {
            self::assertSame('credential_configuration_error', $error->category);
        }
        try {
            $this->f->begin();
            self::fail('Malformed schema reached authorization');
        } catch (CredentialException $error) {
            self::assertSame('credential_configuration_error', $error->category);
            self::assertSame([], $this->f->login->http->requests);
        }
        $schema->drop($this->f->table);
        CredentialSchema::create($schema, $this->f->table);
        $this->f->store->ready();
        self::assertSame(302, $this->f->client->beginManagedLogin(ManagedLoginPolicy::ordinary())->getStatusCode());
    }

    public function test_offline_restore_invalidation_removes_all_old_references_and_fences_stale_writes(): void
    {
        $this->f->begin();
        $context = $this->f->client->bindCredentialContext($this->f->result()->credentialContext());
        $old = $this->f->store->metadata($context->recordId());
        $backup = (array) $this->f->db->table($this->f->table)->where('id', $context->recordId())->first();
        $this->f->client->deleteCredentialContext($context);
        // Reproduce whole-row rollback: integrity is valid, but freshness is not detectable.
        $this->f->db->table($this->f->table)->where('id', $context->recordId())->delete();
        $this->f->db->table($this->f->table)->insert($backup);
        self::assertSame($context->serverReference(), $this->f->client->restoreCredentialContext($context->serverReference())->serverReference());
        // Documented offline operator action: no traffic/workers; preserve schema, erase all rows.
        $this->f->db->table($this->f->table)->delete();
        self::assertSame(0, $this->f->db->table($this->f->table)->count());
        try {
            $this->f->store->abandonBinding($context->recordId(), $old['version'], $old['binding']['attempt']);
        } catch (CredentialException) {
        }
        $this->expectException(CredentialException::class);
        $this->f->client->restoreCredentialContext($context->serverReference());
    }

    public function test_independent_manager_binders_preserve_the_winner_and_process_death_releases_no_proof(): void
    {
        foreach (['race', 'crash', 'crash_claim'] as $mode) {
            $this->f->begin();
            $context = $this->f->result()->credentialContext();
            $directory = $this->f->login->directory;
            $config = $this->f->db->getConfig();
            unset($config['password']);
            file_put_contents($directory.'/binding-input.json', json_encode(['connection' => $config,
                'table' => $this->f->table, 'id' => $context->recordId()]));
            $workers = [];
            foreach ($mode === 'race' ? ['one', 'two'] : [$mode] as $name) {
                $worker = new Process([PHP_BINARY, dirname(__DIR__).'/Concurrency/binding-worker.php', $directory, $name], timeout: 35);
                $worker->start();
                $workers[] = $worker;
            }
            if ($mode === 'race') {
                $deadline = microtime(true) + 25;
                while (! is_file($directory.'/binding-ready-one') || ! is_file($directory.'/binding-ready-two')) {
                    if (microtime(true) > $deadline) {
                        self::fail('Binding worker readiness timeout');
                    }
                    usleep(10000);
                }
                file_put_contents($directory.'/binding-release', 'release');
            }
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $results[] = $worker->getOutput();
            }
            self::assertSame($mode === 'crash_claim' ? 'binding' : 'active', $this->f->store->metadata($context->recordId())['status']);
            if ($mode === 'race') {
                $successes = array_values(array_filter($results, fn ($value) => str_contains($value, '.')));
                self::assertCount(1, $successes);
                self::assertContains('credential_binding_failure', $results);
                $winner = $this->f->client->restoreCredentialContext($successes[0]);
                self::assertSame($context->recordId(), $winner->recordId());
                $this->f->host->account = 'different-owner';
                try {
                    $this->f->client->restoreCredentialContext($successes[0]);
                    self::fail('Foreign owner accepted');
                } catch (CredentialException) {
                    self::assertSame('active', $this->f->store->metadata($context->recordId())['status']);
                }
                $this->f->host->account = 'local-7';
            } else {
                self::assertSame([$mode === 'crash_claim' ? 'crashed-after-binding-claim' : 'crashed-after-binding-write'], $results);
            }
            try {
                $this->f->client->restoreCredentialContext($context->serverReference());
                self::fail('Unreleased completion proof accepted');
            } catch (CredentialException) {
                self::assertSame($mode === 'crash_claim' ? 'binding' : 'active', $this->f->store->metadata($context->recordId())['status']);
            }
        }
    }
}
