<?php

declare(strict_types=1);

namespace Doxa\Laravel;

use Doxa\Laravel\Authorization\BrowserContext;
use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Contracts\TransactionStore;
use Doxa\Laravel\Contracts\Transport;
use Doxa\Laravel\Discovery\Discovery;
use Doxa\Laravel\Exceptions\ConfigurationException;
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
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/doxa.php' => config_path('doxa.php')], 'doxa-config');
    }
}
