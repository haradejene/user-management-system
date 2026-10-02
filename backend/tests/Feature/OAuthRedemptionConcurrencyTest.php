<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Services\OAuthClientService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OAuthRedemptionConcurrencyTest extends TestCase
{
    // Fixtures must be committed and visible to both independent connections.
    use DatabaseMigrations {
        runDatabaseMigrations as private runPostgresqlMigrations;
    }

    public function runDatabaseMigrations(): void
    {
        // Do not run migrations or rollback hooks for SQLite's skipped tests.
        if (env('PGSQL_TEST_SUITE')) {
            $this->runPostgresqlMigrations();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('PGSQL_TEST_SUITE') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent redemption requires the isolated PostgreSQL suite.');
        }
    }

    public static function scopes(): array
    {
        return ['OIDC' => ['openid'], 'OAuth' => ['iam:read']];
    }

    #[DataProvider('scopes')]
    public function test_concurrent_authorization_code_redemption_issues_exactly_one_token_set(string $scope): void
    {
        [$user, $parameters] = $this->authorization($scope);
        $results = $this->race($parameters, 'oauth_auth_codes');
        $this->assertSingleSuccess($results);
        $success = collect($results)->firstWhere('status', 200);
        $this->assertSame($scope === 'openid' ? $user->public_id : null, $success['sub']);
        $this->assertSame($scope === 'openid' ? 'race-nonce' : null, $success['nonce']);
        $this->assertDatabaseCount('oauth_auth_codes', 1);
        $this->assertDatabaseHas('oauth_auth_codes', ['revoked' => true]);
        $this->assertDatabaseCount('oauth_access_tokens', 1);
        $this->assertDatabaseCount('oauth_refresh_tokens', 1);
        $this->assertSame(1, DB::table('oauth_access_tokens')->where('revoked', false)->count());
        $this->postJson('/oauth/token', $parameters)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
    }

    #[DataProvider('scopes')]
    public function test_concurrent_refresh_token_redemption_rotates_exactly_once(string $scope): void
    {
        [, $parameters] = $this->authorization($scope);
        $initial = $this->postJson('/oauth/token', $parameters)->assertOk();
        $refresh = ['grant_type' => 'refresh_token', 'client_id' => $parameters['client_id'], 'refresh_token' => $initial->json('refresh_token')];
        $results = $this->race($refresh, 'oauth_refresh_tokens');
        $this->assertSingleSuccess($results);
        $success = collect($results)->firstWhere('status', 200);
        $this->assertNotContains('id_token', $success['fields']);
        $this->assertDatabaseCount('oauth_access_tokens', 2);
        $this->assertDatabaseCount('oauth_refresh_tokens', 2);
        $this->assertSame(1, DB::table('oauth_access_tokens')->where('revoked', false)->count());
        $this->assertSame(1, DB::table('oauth_refresh_tokens')->where('revoked', false)->count());
        $this->assertSame(1, DB::table('oauth_refresh_tokens')->where('revoked', true)->count());
        $this->postJson('/oauth/token', $refresh)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
    }

    public function test_invalid_first_exchange_releases_code_for_the_waiting_valid_request(): void
    {
        [, $parameters] = $this->authorization('openid');
        $results = $this->race([...$parameters, 'code_verifier' => str_repeat('x', 48)], 'oauth_auth_codes', $parameters);
        $this->assertSame([400, 200], array_column($results, 'status'));
        $this->assertNull($results[0]['access_id']);
        $this->assertNotNull($results[1]['access_id']);
        $this->assertSame('race-nonce', $results[1]['nonce']);
        $this->assertDatabaseCount('oauth_access_tokens', 1);
        $this->assertDatabaseCount('oauth_refresh_tokens', 1);
        $this->assertDatabaseHas('oauth_auth_codes', ['revoked' => true]);
    }

    private function assertSingleSuccess(array $results): void
    {
        $this->assertSame([200, 400], array_column($results, 'status'));
        $this->assertSame('invalid_grant', $results[1]['error']);
        $this->assertNotNull($results[0]['access_id']);
        $this->assertNull($results[1]['access_id']);
        $this->assertNotContains('access_token', $results[1]['fields']);
        $this->assertNotContains('refresh_token', $results[1]['fields']);
        $this->assertNotContains('id_token', $results[1]['fields']);
    }

    private function authorization(string $scope): array
    {
        $user = User::factory()->create();
        $application = Application::factory()->create();
        $user->applications()->attach($application, ['status' => 'active']);
        $client = app(OAuthClientService::class)->create($application, ['name' => 'Race test', 'redirect_uris' => ['https://client.example.test/callback']]);
        $verifier = str_repeat('v', 48);
        $query = ['response_type' => 'code', 'client_id' => $client->id, 'redirect_uri' => $client->redirect_uris[0], 'scope' => $scope, 'nonce' => 'race-nonce', 'prompt' => 'consent', 'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
        $this->withoutMiddleware(VerifyCsrfToken::class)->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query($query))->assertOk();
        $approved = $this->post('/oauth/authorize', ['auth_token' => $this->app['session.store']->get('authToken')])->assertRedirect();
        parse_str(parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $callback);

        return [$user, ['grant_type' => 'authorization_code', 'client_id' => $client->id, 'redirect_uri' => $client->redirect_uris[0], 'code' => $callback['code'], 'code_verifier' => $verifier]];
    }

    private function race(array $parameters, string $table, ?array $secondParameters = null): array
    {
        $name = 'iam-race-'.Str::random(12);
        $base = [
            'database' => config('database.connections.pgsql'), 'key' => config('app.key'),
            'private_key' => config('passport.private_key'), 'public_key' => config('passport.public_key'),
            'issuer' => config('oidc.issuer'), 'parameters' => $parameters, 'table' => $table,
        ];
        $input = new InputStream;
        $first = $this->worker($input);
        $second = $this->worker(json_encode([...$base, 'parameters' => $secondParameters ?? $parameters, 'name' => $name.'-second', 'hold' => false], JSON_THROW_ON_ERROR)."\n");
        try {
            $first->start();
            $input->write(json_encode([...$base, 'name' => $name.'-first', 'hold' => true], JSON_THROW_ON_ERROR)."\n");
            $this->await(fn () => str_contains($first->getOutput(), '"event":"locked"'), $first);
            $second->start();
            $this->await(fn () => str_contains($second->getOutput(), '"event":"attempt"'), $second);
            $blocked = false;
            $this->await(function () use ($name, &$blocked): bool {
                $blocked = (bool) DB::selectOne('SELECT EXISTS (SELECT 1 FROM pg_stat_activity waiting JOIN pg_stat_activity holding ON holding.pid = ANY(pg_blocking_pids(waiting.pid)) WHERE waiting.application_name = ? AND holding.application_name = ?) AS blocked', [$name.'-second', $name.'-first'])->blocked;

                return $blocked;
            }, $second);
            $this->assertTrue($blocked, 'The second HTTP exchange must actually wait on the first database lock.');
            $input->write("release\n");
            $input->close();
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());

            return array_map(function (Process $process): array {
                $messages = array_map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_filter(explode("\n", trim($process->getOutput()))));

                return collect($messages)->firstWhere('event', 'result');
            }, [$first, $second]);
        } finally {
            $input->close();
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
        }
    }

    private function worker(mixed $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/oauth-token-race-worker.php')], base_path(), ['APP_ENV' => 'testing', 'IAM_TOKEN_RACE_WORKER' => '1']);
        $process->setInput($input);
        $process->setTimeout(40);

        return $process;
    }

    private function await(callable $condition, Process $process): void
    {
        $deadline = microtime(true) + 15;
        while (! $condition()) {
            if (! $process->isRunning() || microtime(true) > $deadline) {
                $this->fail('Concurrency worker did not reach its barrier: '.$process->getErrorOutput());
            }
            usleep(10000);
        }
    }
}
