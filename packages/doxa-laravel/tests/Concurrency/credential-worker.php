<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Doxa\Laravel\Authorization\Authorization;
use Doxa\Laravel\Authorization\BrowserContext;
use Doxa\Laravel\Credentials\CredentialManager;
use Doxa\Laravel\Credentials\DatabaseCredentialStore;
use Doxa\Laravel\Credentials\LaravelCredentialCipher;
use Doxa\Laravel\DoxaClient;
use Doxa\Laravel\Exceptions\CredentialException;
use Doxa\Laravel\Oidc\IdTokenValidator;
use Doxa\Laravel\Oidc\UserInfo;
use Doxa\Laravel\Tests\Fixtures\CrashTransactionStore;
use Doxa\Laravel\Tests\Fixtures\CredentialHost;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Token\CodeExchange;
use Illuminate\Database\Capsule\Manager;

[$script, $directory, $worker, $operation] = $argv;
$input = json_decode(file_get_contents($directory.'/credential-input.json'), true, flags: JSON_THROW_ON_ERROR);
$config = $input['connection'];
if ($config['driver'] === 'pgsql') {
    $config['password'] = getenv('PGPASSWORD') ?: '';
}
$capsule = new Manager;
$capsule->addConnection($config);
$db = $capsule->getConnection();
if ($config['driver'] === 'sqlite') {
    $db->statement('PRAGMA busy_timeout=10000');
}
$store = new DatabaseCredentialStore($db, new LaravelCredentialCipher(str_repeat('k', 32), 'test-key'), $input['table'], 60);
if ($operation === 'callback_crash') {
    $h = new Harness([], $directory);
    $h->query = $input['query'];
    $transactions = new CrashTransactionStore($h->store);
    $manager = new CredentialManager($h->config, fn () => ['store' => $store,
        'host' => new CredentialHost, 'ttl' => 3600, 'unbound_ttl' => 300]);
    $client = new DoxaClient($h->config, $h->discovery, new Authorization($h->discovery),
        new BrowserContext, $transactions,
        new CodeExchange($h->config, $h->http, $transactions),
        new IdTokenValidator($h->jwks, $transactions), new UserInfo($h->http), $h->request(), $manager);
    $client->handleManagedCallback($h->callback());
    exit(3);
}
$record = $store->metadata($input['id']);
file_put_contents($directory.'/credential-ready-'.$worker, 'ready');
$deadline = microtime(true) + 20;
while (! is_file($directory.'/credential-release')) {
    if (microtime(true) > $deadline) {
        exit(2);
    }
    usleep(10000);
}
try {
    if ($operation === 'bind') {
        $store->transition($input['id'], $record['version'], 'awaiting_binding', 'binding', ['attempt' => str_repeat('b', 64), 'proof_hash' => str_repeat('c', 64)]);
        $store->transition($input['id'], $record['version'] + 1, 'binding', 'active', ['account' => 'local-7', 'session' => str_repeat('a', 64), 'attempt' => str_repeat('b', 64), 'proof_hash' => str_repeat('c', 64)]);
    } elseif ($operation === 'delete') {
        // Deletion may retry only local CAS conflict, never a network/grant operation.
        for ($i = 0; $i < 4; $i++) {
            try {
                $latest = $store->metadata($input['id']);
                $store->delete($input['id'], $latest['version']);
                break;
            } catch (CredentialException $error) {
                if ($error->category !== 'credential_lifecycle_failure') {
                    throw $error;
                }
            }
        }
    } elseif ($operation === 'cleanup') {
        $store->cleanup();
    }
    $latest = $store->metadata($input['id']);
    echo json_encode(['result' => 'success', 'status' => $latest['status'], 'secrets_returned' => isset($latest['access_token']) || isset($latest['refresh_token'])]);
} catch (CredentialException $error) {
    echo json_encode(['result' => $error->category]);
}
