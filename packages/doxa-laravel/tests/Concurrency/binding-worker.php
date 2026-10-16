<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Doxa\Laravel\Contracts\CredentialHostBinding;
use Doxa\Laravel\Credentials\CredentialContext;
use Doxa\Laravel\Credentials\CredentialManager;
use Doxa\Laravel\Credentials\DatabaseCredentialStore;
use Doxa\Laravel\Credentials\LaravelCredentialCipher;
use Doxa\Laravel\Credentials\ManagedLoginPolicy;
use Doxa\Laravel\Exceptions\CredentialException;
use Doxa\Laravel\Tests\Fixtures\CredentialHost;
use Doxa\Laravel\Tests\Fixtures\FaultCredentialStore;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Http\Request;

[$script, $directory, $name] = $argv;
$input = json_decode(file_get_contents($directory.'/binding-input.json'), true, flags: JSON_THROW_ON_ERROR);
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
$host = new class($directory, $name) implements CredentialHostBinding
{
    private CredentialHost $host;

    public function __construct(private string $directory, private string $name)
    {
        $this->host = new CredentialHost;
    }

    public function capture(Request $request, ManagedLoginPolicy $policy): array
    {
        return $this->host->capture($request, $policy);
    }

    public function validate(Request $request, array $metadata, string $operation): void
    {
        $this->host->validate($request, $metadata, $operation);
        if ($operation === 'bind' && ! str_starts_with($this->name, 'crash')) {
            file_put_contents($this->directory.'/binding-ready-'.$this->name, 'ready');
            $deadline = microtime(true) + 25;
            while (! is_file($this->directory.'/binding-release')) {
                if (microtime(true) > $deadline) {
                    exit(2);
                }
                usleep(10000);
            }
        }
    }

    public function complete(Request $request, array $metadata): array
    {
        $association = $this->host->complete($request, $metadata);

        return $association;
    }
};
$h = new Harness([], $directory);
$adapter = str_starts_with($name, 'crash') ? new FaultCredentialStore($store, $name === 'crash' ? 'binding_crash' : 'binding_claim_crash') : $store;
$manager = new CredentialManager($h->config, fn () => ['store' => $adapter, 'host' => $host, 'ttl' => 3600, 'unbound_ttl' => 300]);
try {
    echo $manager->bind(CredentialContext::fromServerReference($input['id']), $h->request())->serverReference();
} catch (CredentialException $error) {
    echo $error->category;
}
