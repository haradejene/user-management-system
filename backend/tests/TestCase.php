<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if (env('PGSQL_TEST_SUITE')) {
            if ($app->configurationIsCached() || ! $app->environment('testing')
                || config('database.default') !== 'pgsql') {
                throw new \RuntimeException('PostgreSQL tests require uncached testing configuration and the pgsql connection.');
            }
            // A single test-only search path also scopes Laravel migrate:fresh.
            // Never include public: missing test tables must not resolve there.
            $app['config']->set('database.connections.pgsql.search_path', 'iam_backend_test');
            $app['db']->purge('pgsql');
            $connection = $app['db']->connection('pgsql');
            $connection->statement('CREATE SCHEMA IF NOT EXISTS iam_backend_test');
            if ($connection->getDriverName() !== 'pgsql'
                || $connection->selectOne('SELECT current_schema() AS schema')->schema !== 'iam_backend_test') {
                throw new \RuntimeException('PostgreSQL test schema isolation failed.');
            }
        }

        return $app;
    }

    public function actingAs(Authenticatable $user, $guard = null)
    {
        // actingAs bypasses the real Login event; emulate its session stamp.
        $this->withHeader('Origin', 'http://localhost:3000');
        $this->withSession(['auth_session_version' => (int) $user->session_version]);

        return parent::actingAs($user, $guard);
    }
}
