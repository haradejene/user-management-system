<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Doxa\Laravel\Credentials\CredentialContext;
use Doxa\Laravel\Credentials\DatabaseCredentialStore;
use Doxa\Laravel\Credentials\LaravelCredentialCipher;
use Doxa\Laravel\Credentials\RefreshAttempt;
use Doxa\Laravel\Exceptions\CredentialException;
use Illuminate\Database\Capsule\Manager;

[$script, $directory, $name, $operation] = $argv;
$input = json_decode(file_get_contents($directory.'/refresh-input.json'), true, flags: JSON_THROW_ON_ERROR);
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
$store = new DatabaseCredentialStore($db, new LaravelCredentialCipher(str_repeat('k', 32), 'test-key'), $input['table']);
$context = CredentialContext::fromServerReference($input['reference']);
$expected = $store->metadata($context->recordId());
file_put_contents($directory.'/refresh-ready-'.$name, 'ready');
$deadline = microtime(true) + 25;
while (! is_file($directory.'/refresh-release')) {
    if (microtime(true) > $deadline) {
        exit(2);
    }
    usleep(10000);
}
try {
    if (in_array($operation, ['claim', 'crash_claim', 'crash_dispatch'], true)) {
        $attempt = $store->claimRefresh($context, $expected, 30);
        if ($operation === 'crash_dispatch') {
            $store->dispatchRefresh($context, $store->metadata($context->recordId()), $attempt);
        }
    } elseif ($operation === 'complete') {
        $store->completeRefresh($context, $expected, new RefreshAttempt(...$input['attempt']), $input['pair']);
    } elseif ($operation === 'delete') {
        // Retry only local CAS; never a provider request.
        for ($i = 0; $i < 5; $i++) {
            try {
                $record = $store->metadata($context->recordId());
                $store->delete($context->recordId(), $record['version']);
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
    echo 'success';
    // Separate process exits without releasing a crash-mode claim/dispatch marker.
    exit(0);
} catch (CredentialException $error) {
    echo $error->category;
}
