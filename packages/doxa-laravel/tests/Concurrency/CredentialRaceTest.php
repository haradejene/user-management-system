<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Concurrency;

use Doxa\Laravel\Exceptions\CredentialException;
use Doxa\Laravel\Exceptions\DoxaException;
use Doxa\Laravel\Tests\Fixtures\CredentialFixture;
use Doxa\Laravel\Tests\Fixtures\Harness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CredentialRaceTest extends TestCase
{
    public function test_process_death_after_staging_cannot_activate_or_reexchange(): void
    {
        $f = new CredentialFixture;
        try {
            $f->begin();
            $config = $f->db->getConfig();
            unset($config['password']);
            file_put_contents($f->login->directory.'/credential-input.json', json_encode(['connection' => $config,
                'table' => $f->table, 'query' => $f->login->query]));
            $worker = new Process([PHP_BINARY, __DIR__.'/credential-worker.php', $f->login->directory, 'crash', 'callback_crash'], timeout: 35);
            $worker->mustRun();
            self::assertSame('crashed-after-stage', $worker->getOutput());
            $row = $f->db->table($f->table)->first();
            self::assertSame('staged', $row->status);
            try {
                $f->client->restoreCredentialContext($row->id);
                self::fail('Crashed callback context became available');
            } catch (CredentialException) {
                self::assertSame('staged', $f->store->metadata($row->id)['status']);
            }
            try {
                $f->result();
                self::fail('Crashed callback was reopened');
            } catch (DoxaException) {
                self::assertSame(0, $f->login->http->count(Harness::TOKEN));
            }
            $f->mutate($row->id, ['expires_at' => time() - 1]);
            self::assertSame(1, $f->store->cleanup());
        } finally {
            $f->cleanup();
        }
    }

    public static function operations(): array
    {
        return [['delete', false], ['cleanup', true], ['inspect', false]];
    }

    #[DataProvider('operations')]
    public function test_independent_connections_fence_binding_cleanup_and_survive_restart(string $operation, bool $expired): void
    {
        $f = new CredentialFixture;
        try {
            $f->begin();
            $context = $f->result()->credentialContext();
            if ($expired) {
                $f->mutate($context->serverReference(), ['expires_at' => time() - 1]);
            }
            $config = $f->db->getConfig();
            // Database password stays in inherited process environment, never a fixture file.
            unset($config['password']);
            $directory = $f->login->directory;
            file_put_contents($directory.'/credential-input.json', json_encode(['connection' => $config, 'table' => $f->table, 'id' => $context->serverReference()]));
            $workers = $operation === 'inspect' ? [new Process([PHP_BINARY, __DIR__.'/credential-worker.php', $directory, 'one', 'inspect'], timeout: 35)]
                : [new Process([PHP_BINARY, __DIR__.'/credential-worker.php', $directory, 'one', 'bind'], timeout: 35),
                    new Process([PHP_BINARY, __DIR__.'/credential-worker.php', $directory, 'two', $operation], timeout: 35)];
            foreach ($workers as $worker) {
                $worker->start();
            }
            $deadline = microtime(true) + 25;
            foreach (array_keys($workers) as $index) {
                while (! is_file($directory.'/credential-ready-'.($index === 0 ? 'one' : 'two'))) {
                    if (microtime(true) > $deadline) {
                        self::fail('Credential worker readiness timeout');
                    }
                    usleep(10000);
                }
            }
            file_put_contents($directory.'/credential-release', 'release');
            foreach ($workers as $worker) {
                $worker->wait();
                self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                self::assertContains($result['result'], ['success', 'credential_lifecycle_failure']);
                self::assertFalse($result['secrets_returned'] ?? false);
            }
            self::assertSame(match ($operation) {
                'delete' => 'deleted', 'cleanup' => 'expired', default => 'awaiting_binding'
            },
                $f->store->metadata($context->serverReference())['status']);
        } finally {
            $f->cleanup();
        }
    }
}
