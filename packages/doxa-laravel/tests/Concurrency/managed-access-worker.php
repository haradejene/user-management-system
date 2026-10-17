<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Doxa\Laravel\Credentials\CredentialContext;
use Doxa\Laravel\Credentials\CredentialManager;
use Doxa\Laravel\Credentials\DatabaseCredentialStore;
use Doxa\Laravel\Credentials\LaravelCredentialCipher;
use Doxa\Laravel\Exceptions\DoxaException;
use Doxa\Laravel\Tests\Fixtures\CredentialHost;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Tests\Fixtures\RefreshUpdateBarrier;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Psr\Http\Message\RequestInterface;

[$script, $directory, $name] = $argv;
$input = json_decode(file_get_contents($directory.'/managed-input.json'), true, flags: JSON_THROW_ON_ERROR);
$capsule = new Manager;
$configuration = $input['connection'];
if ($configuration['driver'] === 'pgsql') {
    $configuration['password'] = getenv('PGPASSWORD') ?: '';
}
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
    // Both independent workers have validated the original live version immediately before CAS.
    file_put_contents($directory.'/managed-ready-'.$name, 'ready');
    $deadline = microtime(true) + 25;
    while (! is_file($directory.'/managed-release')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Barrier timeout');
        }
        usleep(10000);
    }
}];
$handler = static function (#[SensitiveParameter] RequestInterface $request, #[SensitiveParameter] array $options) use ($directory) {
    if ($request->getMethod() === 'POST') {
        file_put_contents($directory.'/managed-sends', "refresh\n", FILE_APPEND | LOCK_EX);

        return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'WORKER-NEW-ACCESS', 'refresh_token' => 'WORKER-NEW-REFRESH',
            'token_type' => 'Bearer', 'expires_in' => 900])));
    }

    return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{"effective":true}'));
};
$store = new DatabaseCredentialStore($db, new LaravelCredentialCipher(str_repeat('k', 32), 'test-key'), $input['table'], 60, $handler);
$h = new Harness([], $directory.'/managed-worker-'.$name);
$manager = new CredentialManager($h->config, fn () => ['store' => $store, 'host' => new CredentialHost,
    'ttl' => 3600, 'unbound_ttl' => 300]);
try {
    $decision = $manager->checkApplicationAccess(CredentialContext::fromServerReference($input['reference']), $h->request(), fn () => $h->discovery->discover());
    echo $decision->effective ? 'effective' : 'unsafe';
} catch (DoxaException $error) {
    echo $error->category;
}
