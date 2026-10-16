<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Integration;

use Doxa\Laravel\Contracts\CredentialHostBinding;
use Doxa\Laravel\Contracts\Transport;
use Doxa\Laravel\Credentials\ManagedLoginPolicy;
use Doxa\Laravel\DoxaClient;
use Doxa\Laravel\DoxaServiceProvider;
use Doxa\Laravel\Exceptions\CredentialException;
use Doxa\Laravel\Tests\Fixtures\CredentialHost;
use Doxa\Laravel\Tests\Fixtures\FakeTransport;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Orchestra\Testbench\TestCase;

final class ManagedServiceProviderTest extends TestCase
{
    private string $directory;

    private FakeTransport $http;

    protected function getPackageProviders($app): array
    {
        return [DoxaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->directory = sys_get_temp_dir().'/doxa-managed-provider-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700, true);
        touch($this->directory.'/credentials.sqlite');
        $app['config']->set('doxa', Harness::config());
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('session.driver', 'file');
        $app['config']->set('session.secure', true);
        $app['config']->set('session.http_only', true);
        $app['config']->set('session.same_site', 'lax');
        $app['config']->set('cache.default', 'file');
        $app['config']->set('cache.stores.file.path', $this->directory.'/cache');
        $app['config']->set('database.default', 'managed');
        $app['config']->set('database.connections.managed', ['driver' => 'sqlite', 'database' => $this->directory.'/credentials.sqlite', 'prefix' => '']);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            (new Filesystem)->deleteDirectory($this->directory);
        }
    }

    private function client(): DoxaClient
    {
        $this->http = new FakeTransport;
        $this->http->responses[Harness::DISCOVERY] = Harness::metadata();
        $this->app->instance(Transport::class, $this->http);
        $request = Request::create('https://host.example.test/login');
        $session = new Store('test', new ArraySessionHandler(120));
        $session->setId(str_repeat('a', 40));
        $session->start();
        $request->setLaravelSession($session);
        $this->app->instance('request', $request);

        return $this->app->make(DoxaClient::class);
    }

    public function test_identity_only_resolution_and_login_need_no_managed_configuration(): void
    {
        $client = $this->client();
        self::assertSame(302, $client->beginLogin()->getStatusCode());
        self::assertFalse($this->app['db']->connection()->getSchemaBuilder()->hasTable('doxa_credential_contexts'));
        self::assertFalse($this->app->bound(CredentialHostBinding::class));
    }

    public function test_managed_configuration_missing_key_never_falls_back_to_app_key(): void
    {
        $this->app['config']->set('doxa.credentials.enabled', true);
        $this->app->instance(CredentialHostBinding::class, new CredentialHost);
        $client = $this->client();
        try {
            $client->beginManagedLogin(ManagedLoginPolicy::ordinary());
            self::fail();
        } catch (CredentialException) {
            self::assertSame([], $this->http->requests);
        }
    }

    public function test_managed_configuration_missing_host_adapter_fails_before_redirect(): void
    {
        $this->app['config']->set('doxa.credentials', ['enabled' => true, 'key' => str_repeat('k', 32), 'key_id' => 'test']);
        $client = $this->client();
        try {
            $client->beginManagedLogin(ManagedLoginPolicy::ordinary());
            self::fail();
        } catch (CredentialException) {
            self::assertSame([], $this->http->requests);
        }
    }

    public function test_explicit_configuration_and_installed_schema_allow_managed_login(): void
    {
        $this->app['config']->set('doxa.credentials', ['enabled' => true, 'key' => str_repeat('k', 32), 'key_id' => 'test', 'table' => 'custom_credentials']);
        $this->app->instance(CredentialHostBinding::class, new CredentialHost);
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_15_000000_create_doxa_credential_contexts.php';
        $migration->up();
        self::assertSame(302, $this->client()->beginManagedLogin(ManagedLoginPolicy::ordinary())->getStatusCode());
        self::assertNotEmpty(DoxaServiceProvider::pathsToPublish(DoxaServiceProvider::class, 'doxa-credentials-migrations'));
        $this->artisan('doxa:credentials:cleanup', ['--limit' => '10'])->assertExitCode(0);
        $this->artisan('doxa:credentials:cleanup', ['--limit' => '0'])->assertExitCode(1);
    }
}
