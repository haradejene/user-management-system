<?php

// Test-only independent HTTP worker. Configuration and test credentials travel
// through stdin, never command arguments, generated files or test output.
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || getenv('IAM_TOKEN_RACE_WORKER') !== '1') {
    exit(1);
}

try {
    $job = json_decode(trim(fgets(STDIN)), true, 512, JSON_THROW_ON_ERROR);
    if (($job['database']['driver'] ?? null) !== 'pgsql'
        || ($job['database']['search_path'] ?? null) !== 'iam_backend_test') {
        throw new RuntimeException('Unsafe test database configuration.');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Concurrency tests require uncached configuration.');
    }
    $app['config']->set([
        'app.env' => 'testing',
        'app.key' => $job['key'],
        'app.debug' => false,
        'database.default' => 'pgsql',
        'database.connections.pgsql' => $job['database'],
        'passport.connection' => 'pgsql',
        'passport.private_key' => $job['private_key'],
        'passport.public_key' => $job['public_key'],
        'oidc.issuer' => $job['issuer'],
        'session.driver' => 'array',
        'cache.default' => 'array',
    ]);
    $app['db']->purge('pgsql');
    $connection = DB::connection();
    if ($connection->selectOne('SELECT current_schema() AS schema')->schema !== 'iam_backend_test') {
        throw new RuntimeException('Concurrency test schema isolation failed.');
    }
    $connection->selectOne("SELECT set_config('application_name', ?, false)", [$job['name']]);
    $matches = static fn (string $sql): bool => str_contains($sql, '"'.$job['table'].'"') && str_contains($sql, 'for update');
    $attempted = false;
    $connection->beforeExecuting(function (string $sql) use ($matches, &$attempted): void {
        if (! $attempted && $matches($sql)) {
            $attempted = true;
            echo json_encode(['event' => 'attempt'])."\n";
            fflush(STDOUT);
        }
    });
    $held = false;
    $connection->listen(function (QueryExecuted $query) use ($matches, $job, &$held): void {
        if ($job['hold'] && ! $held && $matches($query->sql)) {
            $held = true;
            echo json_encode(['event' => 'locked'])."\n";
            fflush(STDOUT);
            if (trim(fgets(STDIN)) !== 'release') {
                throw new RuntimeException('Missing test barrier release.');
            }
        }
    });

    $request = Request::create('/oauth/token', 'POST', $job['parameters'], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $response = $kernel->handle($request);
    $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $decode = static function (?string $jwt): array {
        return $jwt === null ? [] : json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
    };
    $access = $decode($body['access_token'] ?? null);
    $identity = $decode($body['id_token'] ?? null);
    echo json_encode([
        'event' => 'result', 'status' => $response->getStatusCode(),
        'error' => $body['error'] ?? null, 'fields' => array_keys($body),
        'access_id' => $access['jti'] ?? null,
        'sub' => $identity['sub'] ?? null, 'nonce' => $identity['nonce'] ?? null,
    ], JSON_THROW_ON_ERROR)."\n";
    $kernel->terminate($request, $response);
} catch (Throwable $exception) {
    // Never dump configuration, credentials or token response bodies on failure.
    fwrite(STDERR, get_class($exception)."\n");
    exit(1);
}
