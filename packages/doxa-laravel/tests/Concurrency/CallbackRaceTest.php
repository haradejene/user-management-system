<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Concurrency;

use Doxa\Laravel\Tests\Fixtures\Harness;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CallbackRaceTest extends TestCase
{
    public static function stores(): array
    {
        return [['file', 'callback'], ['database', 'callback'], ['file', 'exchange'], ['database', 'exchange']];
    }

    #[DataProvider('stores')]
    public function test_independent_workers_exchange_exactly_once(string $driver, string $mode): void
    {
        for ($round = 0; $round < 3; $round++) {
            $directory = sys_get_temp_dir().'/doxa-race-'.bin2hex(random_bytes(12));
            $h = new Harness([], $directory.'/cache', $driver);
            try {
                $query = $h->begin();
                file_put_contents($directory.'/input.json', json_encode($query));
                $workers = [$this->worker($directory, 'one', $mode, $driver), $this->worker($directory, 'two', $mode, $driver)];
                foreach ($workers as $worker) {
                    $worker->start();
                }
                $this->releaseWhenReady($directory, ['one', 'two']);
                $results = [];
                foreach ($workers as $worker) {
                    $worker->wait();
                    self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                    $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                }
                self::assertCount(1, array_filter($results, fn ($r) => $r['result'] === 'success'), json_encode($results));
                self::assertSame(1, array_sum(array_column($results, 'token_calls')));
                $loser = array_values(array_filter($results, fn ($r) => $r['result'] !== 'success'))[0];
                self::assertContains($loser['result'], ['transaction_replay', 'invalid_state']);
                self::assertNull($h->cache->get('doxa.transaction.'.hash('sha256', $query['state'])));
            } finally {
                (new Filesystem)->deleteDirectory($directory);
            }
        }
    }

    public function test_crashed_claimant_cannot_reopen_attempt(): void
    {
        $directory = sys_get_temp_dir().'/doxa-crash-'.bin2hex(random_bytes(12));
        $h = new Harness([], $directory.'/cache');
        try {
            $query = $h->begin();
            file_put_contents($directory.'/input.json', json_encode($query));
            $crash = $this->worker($directory, 'crash', 'crash');
            $crash->start();
            $this->releaseWhenReady($directory, ['crash']);
            $crash->wait();
            self::assertSame('claimed', json_decode($crash->getOutput(), true)['result']);
            $retry = $this->worker($directory, 'retry');
            $retry->run();
            $result = json_decode($retry->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('transaction_replay', $result['result']);
            self::assertSame(0, $result['token_calls']);
            self::assertNull($h->cache->get('doxa.transaction.'.hash('sha256', $query['state'])));
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    private function worker(string $directory, string $id, string $mode = 'callback', string $driver = 'file'): Process
    {
        return new Process([PHP_BINARY, __DIR__.'/worker.php', $directory, $id, $mode, $driver], timeout: 30);
    }

    private function releaseWhenReady(string $directory, array $ids): void
    {
        $deadline = microtime(true) + 20;
        foreach ($ids as $id) {
            while (! is_file($directory.'/ready-'.$id)) {
                if (microtime(true) > $deadline) {
                    self::fail('Worker readiness timeout');
                }
                usleep(10000);
            }
        }
        file_put_contents($directory.'/release', 'release');
    }
}
