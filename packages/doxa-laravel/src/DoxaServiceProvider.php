<?php

declare(strict_types=1);

namespace Doxa\Laravel;

use Doxa\Laravel\Authorization\BrowserContext;
use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Console\CleanupCredentials;
use Doxa\Laravel\Contracts\CredentialCipher;
use Doxa\Laravel\Contracts\CredentialHostBinding;
use Doxa\Laravel\Contracts\CredentialStore;
use Doxa\Laravel\Contracts\TransactionStore;
use Doxa\Laravel\Contracts\Transport;
use Doxa\Laravel\Credentials\CredentialManager;
use Doxa\Laravel\Credentials\DatabaseCredentialStore;
use Doxa\Laravel\Credentials\LaravelCredentialCipher;
use Doxa\Laravel\Discovery\Discovery;
use Doxa\Laravel\Exceptions\ConfigurationException;
use Doxa\Laravel\Exceptions\CredentialException;
use Doxa\Laravel\Http\GuzzleTransport;
use Doxa\Laravel\Oidc\JwksProvider;
use Doxa\Laravel\Transaction\CacheTransactionStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\ServiceProvider;

final class DoxaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/doxa.php', 'doxa');
        $this->app->scoped(DoxaConfig::class, fn ($app) => new DoxaConfig($app['config']->get('doxa')));
        $this->app->scoped(BrowserContext::class, function ($app) {
            if ($app['config']->get('session.driver') === 'cookie'
                || $app['config']->get('session.secure') !== true
                || $app['config']->get('session.http_only') !== true
                || ! in_array($app['config']->get('session.same_site'), ['lax', 'none'], true)) {
                throw new ConfigurationException('configuration_error');
            }

            return new BrowserContext;
        });
        $this->app->scoped(Transport::class, GuzzleTransport::class);
        $this->app->scoped(Discovery::class, fn ($app) => new Discovery($app->make(DoxaConfig::class), $app->make(Transport::class), $app->make(CacheManager::class)->store($app['config']->get('doxa.cache_store'))));
        $this->app->scoped(JwksProvider::class, fn ($app) => new JwksProvider($app->make(DoxaConfig::class), $app->make(Transport::class), $app->make(CacheManager::class)->store($app['config']->get('doxa.cache_store'))));
        $this->app->scoped(TransactionStore::class, fn ($app) => new CacheTransactionStore($app->make(CacheManager::class)->store($app['config']->get('doxa.cache_store')), $app['encrypter'], $app->make(DoxaConfig::class)));
        $this->app->scoped(DoxaClient::class);
        $this->app->scopedIf(CredentialCipher::class, function ($app) {
            $values = $app['config']->get('doxa.credentials', []);
            if (($values['enabled'] ?? false) !== true || ! is_string($values['key'] ?? null) || ! is_string($values['key_id'] ?? null)) {
                throw new CredentialException('credential_configuration_error');
            }

            return new LaravelCredentialCipher($values['key'], $values['key_id']);
        });
        $this->app->scopedIf(CredentialStore::class, function ($app) {
            try {
                $values = $app['config']->get('doxa.credentials', []);
                if (($values['enabled'] ?? false) !== true) {
                    throw new CredentialException('credential_configuration_error');
                }
                $name = $values['connection'] ?? $app['config']->get('database.default');
                $configuration = $app['config']->get('database.connections.'.$name);
                if (! is_array($configuration)) {
                    throw new CredentialException('credential_configuration_error');
                }
                // Honor Laravel URL/read-write parsing without sharing its PDO or SQL events.
                $configuration = $app['db']->connection($name)->getConfig();
                // Fresh connection, not the host's ambient mapping transaction or query instrumentation.
                $connection = $app['db.factory']->make($configuration, 'doxa_credentials');

                return new DatabaseCredentialStore($connection, $app->make(CredentialCipher::class),
                    $values['table'] ?? 'doxa_credential_contexts', $values['tombstone_seconds'] ?? 3600);
            } catch (CredentialException $error) {
                throw $error;
            } catch (\Throwable) {
                throw new CredentialException('credential_configuration_error');
            }
        });
        $this->app->scoped(CredentialManager::class, fn ($app) => new CredentialManager($app->make(DoxaConfig::class), function () use ($app) {
            $values = $app['config']->get('doxa.credentials', []);
            if (($values['enabled'] ?? false) !== true || ! $app->bound(CredentialHostBinding::class)) {
                throw new CredentialException('credential_configuration_error');
            }

            return ['store' => $app->make(CredentialStore::class), 'host' => $app->make(CredentialHostBinding::class),
                'ttl' => $values['lifetime_seconds'] ?? 3600, 'unbound_ttl' => $values['unbound_seconds'] ?? 300];
        }));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/doxa.php' => config_path('doxa.php')], 'doxa-config');
        $this->publishes([__DIR__.'/../database/migrations/2026_10_15_000000_create_doxa_credential_contexts.php' => database_path('migrations/2026_10_15_000000_create_doxa_credential_contexts.php')], 'doxa-credentials-migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([CleanupCredentials::class]);
        }
    }
}
