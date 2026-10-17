<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Integration;

use Doxa\Laravel\Credentials\CredentialContext;
use Doxa\Laravel\Credentials\CredentialManager;
use Doxa\Laravel\Credentials\DatabaseCredentialStore;
use Doxa\Laravel\Credentials\ManagedLoginPolicy;
use Doxa\Laravel\Exceptions\ApplicationAccessException;
use Doxa\Laravel\Tests\Fixtures\CredentialFixture;
use Doxa\Laravel\Tests\Fixtures\ManagedWriteFault;
use Doxa\Laravel\Tests\Fixtures\TraceArguments;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Process;

final class ManagedApplicationAccessTest extends TestCase
{
    private CredentialFixture $f;

    private CredentialContext $context;

    private array $requests = [];

    private array $responses = [];

    private DatabaseCredentialStore $managedStore;

    protected function setUp(): void
    {
        $this->f = new CredentialFixture;
        $this->installStore($this->f->db);
        $this->f->begin();
        $this->context = $this->f->client->bindCredentialContext($this->f->result()->credentialContext());
    }

    private function installStore(Connection $db): void
    {
        $store = new DatabaseCredentialStore($db, $this->f->cipher, $this->f->table, 60,
            function (#[\SensitiveParameter] RequestInterface $request, #[\SensitiveParameter] array $options) {
                $this->requests[] = [$request, $options];
                $next = array_shift($this->responses);
                if ($next instanceof \Closure) {
                    $next = $next();
                }
                if ($next instanceof \Throwable) {
                    return Create::rejectionFor($next);
                }

                return Create::promiseFor($next ?? new Response(200, ['Content-Type' => 'application/json'], '{"effective":true}'));
            });
        $this->f->configure($store);
        $this->managedStore = $store;
    }

    protected function tearDown(): void
    {
        $this->f->cleanup();
        unset($this->f, $this->managedStore);
        gc_collect_cycles();
    }

    private function nearExpiry(): array
    {
        $this->f->mutate($this->context->recordId(), ['access_expires_at' => time() + 20]);

        return $this->f->payload($this->context->recordId());
    }

    private function rotated(array $changes = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            ...['access_token' => 'ACCESS_SENTINEL_DO_NOT_LEAK', 'refresh_token' => 'REFRESH_SENTINEL_DO_NOT_LEAK',
                'token_type' => 'Bearer', 'expires_in' => 900], ...$changes], JSON_THROW_ON_ERROR));
    }

    private function failure(string $category): ApplicationAccessException
    {
        try {
            $this->f->client->checkApplicationAccess($this->context);
            self::fail('Unsafe success');
        } catch (ApplicationAccessException $error) {
            self::assertSame($category, $error->category);
            self::assertNull($error->getPrevious());
            foreach (['ACCESS_SENTINEL_DO_NOT_LEAK', 'REFRESH_SENTINEL_DO_NOT_LEAK', 'SECRET_SENTINEL_DO_NOT_LEAK'] as $secret) {
                self::assertStringNotContainsString($secret, @var_export($error, true));
                self::assertStringNotContainsString($secret, print_r($error, true));
                self::assertStringNotContainsString($secret, TraceArguments::inspect(array_values(array_filter($error->getTrace(), static fn ($frame) => str_starts_with($frame['class'] ?? '', 'Doxa\\Laravel\\') && ! str_contains($frame['class'], '\\Tests\\')))));
            }

            return $error;
        }
    }

    private function erased(): void
    {
        $record = $this->f->payload($this->context->recordId());
        self::assertSame('unusable', $record['status']);
        self::assertArrayNotHasKey('access_token', $record);
        self::assertArrayNotHasKey('refresh_token', $record);
    }

    public function test_valid_access_is_reused_fixed_get_is_uncached_and_has_no_selectors(): void
    {
        $before = $this->f->payload($this->context->recordId());
        for ($i = 0; $i < 2; $i++) {
            $decision = $this->f->client->checkApplicationAccess($this->context);
            self::assertTrue($decision->effective);
            self::assertSame('{"effective":true}', json_encode($decision));
            self::assertStringNotContainsString($before['access_token'], var_export($decision, true));
        }
        self::assertCount(2, $this->requests);
        foreach ($this->requests as [$request, $options]) {
            self::assertSame('GET', $request->getMethod());
            self::assertSame('https://iam.example.test/oauth/application-access', (string) $request->getUri());
            self::assertSame('', (string) $request->getBody());
            self::assertSame('Bearer '.$before['access_token'], $request->getHeaderLine('Authorization'));
            self::assertTrue($options['verify']);
            self::assertFalse($options['allow_redirects']);
            self::assertFalse($options['cookies']);
        }
        self::assertSame($before['refresh_token'], $this->f->payload($this->context->recordId())['refresh_token']);
    }

    public function test_lazy_refresh_rotates_once_then_uses_only_persisted_new_access(): void
    {
        // Represent an already protected broader grant; the response must persist narrowing.
        $this->f->mutate($this->context->recordId(), ['scopes' => ['openid', 'profile'], 'scope_ceiling' => ['openid', 'profile']]);
        $before = $this->nearExpiry();
        $this->responses[] = $this->rotated(['scope' => 'openid']);
        self::assertTrue($this->f->client->checkApplicationAccess($this->context)->effective);
        self::assertCount(2, $this->requests);
        self::assertSame('POST', $this->requests[0][0]->getMethod());
        parse_str((string) $this->requests[0][0]->getBody(), $form);
        self::assertSame($before['refresh_token'], $form['refresh_token']);
        self::assertSame('refresh_token', $form['grant_type']);
        self::assertArrayNotHasKey('scope', $form);
        self::assertSame('Bearer ACCESS_SENTINEL_DO_NOT_LEAK', $this->requests[1][0]->getHeaderLine('Authorization'));
        $after = $this->f->payload($this->context->recordId());
        self::assertSame('REFRESH_SENTINEL_DO_NOT_LEAK', $after['refresh_token']);
        self::assertSame(['openid'], $after['scopes']);
        self::assertSame($before['absolute_expires_at'], $after['absolute_expires_at']);
        self::assertSame($before['owner'], $after['owner']);
        self::assertSame('active', $after['status']);
        self::assertTrue($this->f->client->checkApplicationAccess($this->context)->effective);
        self::assertCount(3, $this->requests);
    }

    #[DataProvider('resourceErrors')]
    public function test_resource_outcomes_never_refresh_replay_or_confirm_access(int $status, string $body, string $category, bool $terminal): void
    {
        $this->responses[] = new Response($status, ['Content-Type' => 'application/json'], $body);
        $this->failure($category);
        self::assertCount(1, $this->requests);
        if ($terminal) {
            $this->erased();
        } else {
            self::assertSame('active', $this->f->payload($this->context->recordId())['status']);
        }
    }

    public static function resourceErrors(): array
    {
        return [
            [401, '{}', 'credential_invalid', true], [403, '{"error":"insufficient_scope"}', 'access_denied_or_insufficient_scope', true],
            [403, '{"error":"access_denied"}', 'access_denied_or_insufficient_scope', true],
            [500, '{}', 'transient_service_failure', false], [503, '{}', 'transient_service_failure', false],
            [302, '{}', 'protocol_failure', true], [400, '{}', 'protocol_failure', true],
            [200, '{"effective":false}', 'protocol_failure', true], [200, '{"effective":1}', 'protocol_failure', true],
            [200, '{"effective":true,"extra":true}', 'protocol_failure', true],
            [200, '{"effective":false,"effective":true}', 'protocol_failure', true], [200, 'invalid', 'protocol_failure', true],
        ];
    }

    #[DataProvider('refreshErrors')]
    public function test_refresh_failure_erases_pair_and_never_resends(int $status, string $body, string $category): void
    {
        $this->nearExpiry();
        $this->responses[] = new Response($status, ['Content-Type' => 'application/json'], $body);
        $this->failure($category);
        self::assertCount(1, $this->requests);
        $this->erased();
        $this->failure('credential_lifecycle_failure');
        self::assertCount(1, $this->requests);
    }

    public static function refreshErrors(): array
    {
        return [[500, '{}', 'refresh_outcome_uncertain'], [400, '{"error":"invalid_grant"}', 'credential_invalid'],
            [401, '{"error":"invalid_client"}', 'client_unusable'], [302, '{}', 'refresh_outcome_uncertain'],
            [200, 'invalid', 'refresh_contract_violation'], [200, '{}', 'refresh_contract_violation']];
    }

    public function test_timeout_after_refresh_dispatch_is_terminal_not_retried(): void
    {
        $this->nearExpiry();
        $this->responses[] = new \RuntimeException('SECRET_SENTINEL_DO_NOT_LEAK');
        $this->failure('refresh_outcome_uncertain');
        $this->erased();
        self::assertCount(1, $this->requests);
    }

    public function test_resource_timeout_allows_only_a_new_explicit_read_only_check(): void
    {
        $this->responses[] = new \RuntimeException('SECRET_SENTINEL_DO_NOT_LEAK');
        $this->failure('transient_service_failure');
        self::assertCount(1, $this->requests);
        self::assertTrue($this->f->client->checkApplicationAccess($this->context)->effective);
        self::assertCount(2, $this->requests);
    }

    public function test_wrong_owner_and_uncompleted_reference_cause_no_requests(): void
    {
        $this->f->host->account = 'another-owner';
        $this->failure('incompatible_context');
        $this->f->host->account = 'local-7';
        $this->context = CredentialContext::fromServerReference($this->context->recordId());
        $this->failure('incompatible_context');
        self::assertCount(0, $this->requests);
    }

    public function test_pending_link_can_check_and_refresh_without_promotion_or_deadline_extension(): void
    {
        $this->f->begin(ManagedLoginPolicy::pendingLink('link-1'));
        $this->context = $this->f->result()->credentialContext();
        $before = $this->nearExpiry();
        $this->responses[] = $this->rotated();
        self::assertTrue($this->f->client->checkApplicationAccess($this->context)->effective);
        $after = $this->f->payload($this->context->recordId());
        self::assertSame('pending_link', $after['status']);
        self::assertSame('pending_link', $after['purpose']);
        self::assertNull($after['owner']);
        self::assertSame($before['expires_at'], $after['expires_at']);
        self::assertSame($before['policy'], $after['policy']);
    }

    public function test_cancellation_during_refresh_response_cannot_restore_credentials(): void
    {
        $this->nearExpiry();
        $this->responses[] = function () {
            $this->f->client->deleteCredentialContext($this->context);

            return $this->rotated();
        };
        $this->failure('refresh_outcome_uncertain');
        $record = $this->f->payload($this->context->recordId());
        self::assertSame('deleted', $record['status']);
        self::assertArrayNotHasKey('access_token', $record);
        self::assertArrayNotHasKey('refresh_token', $record);
        self::assertCount(1, $this->requests);
    }

    public function test_constructed_invocation_cannot_obtain_credentials_or_send(): void
    {
        try {
            $this->f->manager->consumeAccessInvocation(new \stdClass, $this->f->store);
            self::fail('Forged authority');
        } catch (ApplicationAccessException $error) {
            self::assertSame('incompatible_context', $error->category);
        }
        self::assertCount(0, $this->requests);
    }

    #[DataProvider('acknowledgements')]
    public function test_real_committed_write_acknowledgement_loss_never_repeats_dispatch(int $write, bool $outage): void
    {
        $db = $this->f->db->getDriverName() === 'pgsql'
            ? new class($this->f->db->getPdo(), $this->f->db->getDatabaseName(), '', $this->f->db->getConfig()) extends PostgresConnection
            {
                use ManagedWriteFault;
            }
        : new class($this->f->db->getPdo(), $this->f->db->getDatabaseName(), '', $this->f->db->getConfig()) extends SQLiteConnection
        {
            use ManagedWriteFault;
        };
        $fault = $db->fault = (object) ['writes' => 0, 'target' => $write, 'outage' => $outage, 'unavailable' => false];
        $this->installStore($db);
        $this->nearExpiry();
        $this->responses[] = $this->rotated();
        if ($write === 3 && ! $outage) {
            self::assertTrue($this->f->client->checkApplicationAccess($this->context)->effective);
            self::assertCount(2, $this->requests);
            self::assertSame('REFRESH_SENTINEL_DO_NOT_LEAK', $this->f->payload($this->context->recordId())['refresh_token']);
        } else {
            $this->failure($write === 1 ? 'credential_store_unavailable' : 'refresh_outcome_uncertain');
            self::assertCount($write > 2 ? 1 : 0, $this->requests);
            $fault->unavailable = false;
            if ($write === 2) {
                $record = $this->f->payload($this->context->recordId());
                if ($record['status'] === 'refreshing') {
                    $this->f->mutate($this->context->recordId(), ['expires_at' => time() - 1]);
                    $this->f->store->cleanup();
                }
                $this->erased();
            }
        }
    }

    public static function acknowledgements(): array
    {
        return [[1, false], [2, false], [2, true], [3, false]];
    }

    public function test_access_check_commit_failure_leaves_a_durable_nonusable_fence(): void
    {
        $db = $this->f->db->getDriverName() === 'pgsql'
            ? new class($this->f->db->getPdo(), $this->f->db->getDatabaseName(), '', $this->f->db->getConfig()) extends PostgresConnection
            {
                use ManagedWriteFault;
            }
        : new class($this->f->db->getPdo(), $this->f->db->getDatabaseName(), '', $this->f->db->getConfig()) extends SQLiteConnection
        {
            use ManagedWriteFault;
        };
        $db->fault = (object) ['writes' => 0, 'target' => 1, 'outage' => true, 'unavailable' => false];
        $this->installStore($db);
        $this->failure('credential_store_unavailable');
        self::assertCount(0, $this->requests);
        self::assertSame('checking', $this->f->payload($this->context->recordId())['status']);
        $db->fault->unavailable = false;
        $this->failure('credential_busy');
        $this->f->mutate($this->context->recordId(), ['expires_at' => time() - 1]);
        $this->f->store->cleanup();
        $record = $this->f->payload($this->context->recordId());
        self::assertSame('expired', $record['status']);
        self::assertArrayNotHasKey('access_token', $record);
        self::assertArrayNotHasKey('refresh_token', $record);
    }

    public function test_absent_refresh_token_expires_access_only_context_without_sending(): void
    {
        $this->nearExpiry();
        $this->f->mutate($this->context->recordId(), ['refresh_token' => null]);
        $this->failure('reauthorization_required');
        self::assertCount(0, $this->requests);
        $record = $this->f->payload($this->context->recordId());
        self::assertSame('expired', $record['status']);
        self::assertArrayNotHasKey('access_token', $record);
        self::assertArrayNotHasKey('refresh_token', $record);
    }

    public function test_unchanged_rotation_and_expanded_scope_are_rejected_by_integrated_parser(): void
    {
        $before = $this->nearExpiry();
        $this->responses[] = $this->rotated(['refresh_token' => $before['refresh_token'], 'scope' => 'openid offline_access']);
        $this->failure('refresh_contract_violation');
        self::assertCount(1, $this->requests);
        $this->erased();
    }

    public function test_pending_link_host_cancellation_before_response_commit_erases_credentials(): void
    {
        $this->f->begin(ManagedLoginPolicy::pendingLink('link-1'));
        $this->context = $this->f->result()->credentialContext();
        $this->nearExpiry();
        $this->responses[] = function () {
            $this->f->host->live = false;

            return $this->rotated();
        };
        $this->failure('refresh_outcome_uncertain');
        self::assertCount(1, $this->requests);
        $this->erased();
    }

    public function test_independent_coordinators_racing_the_original_version_send_exactly_once(): void
    {
        $this->nearExpiry();
        $directory = $this->f->login->directory;
        $configuration = $this->f->db->getConfig();
        unset($configuration['password']);
        file_put_contents($directory.'/managed-input.json', json_encode(['connection' => $configuration,
            'table' => $this->f->table, 'reference' => $this->context->serverReference()]));
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, dirname(__DIR__).'/Concurrency/managed-access-worker.php', $directory, (string) $i], timeout: 35);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 25;
            while (! is_file($directory.'/managed-ready-0') || ! is_file($directory.'/managed-ready-1')) {
                if (microtime(true) >= $deadline) {
                    self::fail('Independent coordinator barrier was not reached');
                }
                usleep(10000);
            }
            file_put_contents($directory.'/managed-release', 'release');
            $results = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = $worker->getOutput();
            }
            self::assertContains('effective', $results);
            foreach ($results as $result) {
                self::assertContains($result, ['effective', 'credential_busy']);
            }
            self::assertSame("refresh\n", file_get_contents($directory.'/managed-sends'));
            self::assertSame('WORKER-NEW-REFRESH', $this->f->payload($this->context->recordId())['refresh_token']);
            self::assertTrue($this->f->client->checkApplicationAccess($this->context)->effective);
            self::assertCount(1, $this->requests);
            self::assertSame('GET', $this->requests[0][0]->getMethod());
        } finally {
            file_put_contents($directory.'/managed-release', 'release');
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function test_margin_is_configurable_and_near_expiry_access_is_never_used_past_boundary(): void
    {
        $this->nearExpiry();
        $manager = new CredentialManager($this->f->login->config, fn () => [
            'store' => $this->managedStore, 'host' => $this->f->host, 'ttl' => 3600, 'unbound_ttl' => 300, 'margin' => 0]);
        self::assertTrue($manager->checkApplicationAccess($this->context, $this->f->login->request(), fn () => $this->f->login->discovery->discover())->effective);
        self::assertCount(1, $this->requests);
        self::assertSame('GET', $this->requests[0][0]->getMethod());
        $this->responses[] = $this->rotated();
        self::assertTrue($this->f->client->checkApplicationAccess($this->context)->effective);
        self::assertCount(3, $this->requests);
        self::assertSame('POST', $this->requests[1][0]->getMethod());
    }

    #[DataProvider('boundedResponses')]
    public function test_access_response_limits_media_type_and_truncation_fail_closed(array $headers, string $body): void
    {
        $this->responses[] = new Response(200, $headers, $body);
        $this->failure('protocol_failure');
        $this->erased();
        self::assertCount(1, $this->requests);
    }

    public static function boundedResponses(): array
    {
        return [[['Content-Type' => 'text/html'], '{"effective":true}'],
            [['Content-Type' => 'application/json', 'Content-Length' => '100'], '{"effective":true}'],
            [['Content-Type' => 'application/json'], str_repeat(' ', 262145)]];
    }

    public function test_deletion_during_access_check_blocks_a_late_positive_result(): void
    {
        $this->responses[] = function () {
            $this->f->client->deleteCredentialContext($this->context);

            return new Response(200, ['Content-Type' => 'application/json'], '{"effective":true}');
        };
        $this->failure('credential_lifecycle_failure');
        $record = $this->f->payload($this->context->recordId());
        self::assertSame('deleted', $record['status']);
        self::assertArrayNotHasKey('access_token', $record);
        self::assertCount(1, $this->requests);
    }

    #[DataProvider('outageOperations')]
    public function test_storage_outage_after_provider_response_keeps_work_fenced_until_cleanup(bool $refresh): void
    {
        $db = $this->f->db->getDriverName() === 'pgsql'
            ? new class($this->f->db->getPdo(), $this->f->db->getDatabaseName(), '', $this->f->db->getConfig()) extends PostgresConnection
            {
                use ManagedWriteFault;
            }
        : new class($this->f->db->getPdo(), $this->f->db->getDatabaseName(), '', $this->f->db->getConfig()) extends SQLiteConnection
        {
            use ManagedWriteFault;
        };
        $fault = $db->fault = (object) ['writes' => 0, 'target' => 0, 'outage' => false, 'unavailable' => false];
        $this->installStore($db);
        if ($refresh) {
            $this->nearExpiry();
        }
        $this->responses[] = function () use ($fault, $refresh) {
            $fault->unavailable = true;

            return $refresh ? $this->rotated() : new Response(403, ['Content-Type' => 'application/json'], '{}');
        };
        $this->failure($refresh ? 'refresh_outcome_uncertain' : 'credential_store_unavailable');
        self::assertCount(1, $this->requests);
        $fault->unavailable = false;
        self::assertSame($refresh ? 'refreshing' : 'checking', $this->f->payload($this->context->recordId())['status']);
        $this->failure('credential_busy');
        self::assertCount(1, $this->requests);
        $this->f->mutate($this->context->recordId(), ['expires_at' => time() - 1]);
        $this->f->store->cleanup();
        $record = $this->f->payload($this->context->recordId());
        self::assertSame($refresh ? 'unusable' : 'expired', $record['status']);
        self::assertArrayNotHasKey('access_token', $record);
        self::assertArrayNotHasKey('refresh_token', $record);
    }

    public static function outageOperations(): array
    {
        return [[true], [false]];
    }

    public function test_stream_close_failure_cannot_downgrade_authoritative_401_to_transient(): void
    {
        $closes = 0;
        $stream = FnStream::decorate(Utils::streamFor('{}'), [
            'close' => static function () use (&$closes): void {
                if (++$closes === 1) {
                    throw new \RuntimeException('SECRET_SENTINEL_DO_NOT_LEAK');
                }
            },
        ]);
        $this->responses[] = new Response(401, ['Content-Type' => 'application/json'], $stream);
        $this->failure('credential_invalid');
        $this->erased();
        self::assertCount(1, $this->requests);
    }

    #[DataProvider('confidentialMethods')]
    public function test_refresh_uses_exactly_the_registered_confidential_method_without_disclosure(string $method): void
    {
        $worker = new Process([PHP_BINARY, '-d', 'zend.exception_ignore_args=0', dirname(__DIR__).'/Fixtures/managed-access-trace.php', $method], timeout: 35);
        self::assertSame(0, $worker->run(), $worker->getErrorOutput());
        $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['effective' => true, 'auth' => true, 'clean' => true,
            'category' => 'transient_service_failure', 'requests' => 3, 'cause' => false], $result);
    }

    public static function confidentialMethods(): array
    {
        return [['client_secret_basic'], ['client_secret_post']];
    }
}
