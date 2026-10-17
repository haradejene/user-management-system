<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Doxa\Laravel\Credentials\CredentialContext;
use Doxa\Laravel\Credentials\CredentialManager;
use Doxa\Laravel\Credentials\DatabaseCredentialStore;
use Doxa\Laravel\Credentials\LaravelCredentialCipher;
use Doxa\Laravel\Exceptions\CredentialException;
use Doxa\Laravel\Tests\Fixtures\CredentialHost;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Tests\Fixtures\PendingLinkHost;
use Doxa\Laravel\Tests\Fixtures\RefreshUpdateBarrier;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;

[$script, $directory, $name] = $argv;
$input = json_decode(file_get_contents($directory.'/link-input.json'), true, flags: JSON_THROW_ON_ERROR);
$configuration = $input['connection'];
if ($configuration['driver'] === 'pgsql') {
    $configuration['password'] = getenv('PGPASSWORD') ?: '';
}
$capsule = new Manager;
$capsule->addConnection($configuration);
$connection = $capsule->getConnection();
if ($configuration['driver'] === 'sqlite') {
    $connection->statement('PRAGMA busy_timeout=10000');
}
$db = $configuration['driver'] === 'pgsql'
    ? new class($connection->getPdo(), $connection->getDatabaseName(), '', $connection->getConfig()) extends PostgresConnection
    {
        use RefreshUpdateBarrier;
    }
: new class($connection->getPdo(), $connection->getDatabaseName(), '', $connection->getConfig()) extends SQLiteConnection
{
    use RefreshUpdateBarrier;
};
$db->barrier = (object) ['reached' => false, 'affected' => null, 'before' => function () use ($directory, $name): void {
    file_put_contents($directory.'/link-ready-'.$name, 'ready');
    $deadline = microtime(true) + 25;
    while (! is_file($directory.'/link-release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timeout');
        }
        usleep(10000);
    }
}];
$store = new DatabaseCredentialStore($db, new LaravelCredentialCipher(str_repeat('k', 32), 'test-key'), $input['table'], 60,
    static function () use ($directory) {
        file_put_contents($directory.'/link-checks', "check\n", FILE_APPEND | LOCK_EX);

        return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{"effective":true}'));
    });
$host = new PendingLinkHost($connection, $input['host_table'], new CredentialHost, false);
if ($name === 'crash') {
    $host->afterCommit = static function (): never {
        echo 'crashed-after-local-commit';
        exit(0);
    };
}
$h = new Harness([], $directory.'/link-worker-'.$name);
$manager = new CredentialManager($h->config, fn () => ['store' => $store, 'host' => $host, 'ttl' => 3600, 'unbound_ttl' => 300]);
try {
    echo $manager->completePendingLink(CredentialContext::fromServerReference($input['reference']), $h->request(), fn () => $h->discovery->discover())->serverReference();
} catch (CredentialException $error) {
    echo $error->category;
}
