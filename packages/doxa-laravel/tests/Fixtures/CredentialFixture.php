<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Fixtures;

use Doxa\Laravel\Authorization\Authorization;
use Doxa\Laravel\Authorization\BrowserContext;
use Doxa\Laravel\Contracts\CredentialHostBinding;
use Doxa\Laravel\Contracts\CredentialStore;
use Doxa\Laravel\Contracts\ManagedTransactionStore;
use Doxa\Laravel\Credentials\CredentialManager;
use Doxa\Laravel\Credentials\CredentialSchema;
use Doxa\Laravel\Credentials\DatabaseCredentialStore;
use Doxa\Laravel\Credentials\LaravelCredentialCipher;
use Doxa\Laravel\Credentials\ManagedLoginPolicy;
use Doxa\Laravel\Credentials\ManagedLoginResult;
use Doxa\Laravel\DoxaClient;
use Doxa\Laravel\Oidc\IdTokenValidator;
use Doxa\Laravel\Oidc\UserInfo;
use Doxa\Laravel\Token\CodeExchange;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;

final class CredentialFixture
{
    public Harness $login;

    public Connection $db;

    public string $table;

    public LaravelCredentialCipher $cipher;

    public DatabaseCredentialStore $store;

    public CredentialHost $host;

    public CredentialManager $manager;

    public DoxaClient $client;

    public function __construct()
    {
        $this->login = new Harness;
        if (! is_dir($this->login->directory)) {
            mkdir($this->login->directory, 0700, true);
        }
        $capsule = new Manager;
        if (getenv('DOXA_CREDENTIAL_TEST_DRIVER') === 'pgsql') {
            $database = getenv('DOXA_CREDENTIAL_TEST_DATABASE');
            if (! is_string($database) || ! str_starts_with($database, 'doxa_sdk_')) {
                throw new \RuntimeException('Dedicated doxa_sdk_ test database required.');
            }
            $capsule->addConnection(['driver' => 'pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
                'port' => getenv('PGPORT') ?: '5432', 'database' => $database,
                'username' => getenv('PGUSER') ?: 'postgres', 'password' => getenv('PGPASSWORD') ?: '', 'charset' => 'utf8']);
        } else {
            $path = $this->login->directory.'/credentials.sqlite';
            touch($path);
            $capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '']);
        }
        $this->db = $capsule->getConnection();
        if ($this->db->getDriverName() === 'sqlite') {
            $this->db->statement('PRAGMA busy_timeout=10000');
        }
        $this->table = 'doxa_credential_test_'.bin2hex(random_bytes(8));
        CredentialSchema::create($this->db->getSchemaBuilder(), $this->table);
        $this->cipher = new LaravelCredentialCipher(str_repeat('k', 32), 'test-key');
        $this->store = new DatabaseCredentialStore($this->db, $this->cipher, $this->table, 60);
        $this->host = new CredentialHost;
        $this->configure();
    }

    public function configure(?CredentialStore $store = null, ?ManagedTransactionStore $transactions = null, ?CredentialHostBinding $host = null): void
    {
        $h = $this->login;
        $transactions ??= $h->store;
        $store ??= $this->store;
        $host ??= $this->host;
        $this->manager = new CredentialManager($h->config, fn () => ['store' => $store, 'host' => $host, 'ttl' => 3600, 'unbound_ttl' => 300]);
        $this->client = new DoxaClient($h->config, $h->discovery, new Authorization($h->discovery), new BrowserContext, $transactions,
            new CodeExchange($h->config, $h->http, $transactions), new IdTokenValidator($h->jwks, $transactions), new UserInfo($h->http), $h->request(), $this->manager);
    }

    public function begin(?ManagedLoginPolicy $policy = null): void
    {
        parse_str(parse_url($this->client->beginManagedLogin($policy ?? ManagedLoginPolicy::ordinary())->getTargetUrl(), PHP_URL_QUERY), $this->login->query);
    }

    public function result(): ManagedLoginResult
    {
        return $this->client->handleManagedCallback($this->login->callback());
    }

    public function payload(string $id): array
    {
        $row = $this->db->table($this->table)->where('id', $id)->first();

        return $this->cipher->open($row->payload, $row->key_id);
    }

    public function mutate(string $id, array $changes): void
    {
        $payload = [...$this->payload($id), ...$changes];
        $columns = array_intersect_key($payload, array_flip(['status', 'version', 'expires_at', 'purge_at', 'provenance']));
        $this->db->table($this->table)->where('id', $id)->update([...$columns, 'payload' => $this->cipher->seal($payload)]);
    }

    public function cleanup(): void
    {
        $this->db->getSchemaBuilder()->drop($this->table);
        $this->db->disconnect();
        $this->login->cleanup();
    }
}
