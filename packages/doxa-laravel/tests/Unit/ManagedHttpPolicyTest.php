<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Unit;

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Exceptions\RefreshFailure;
use Doxa\Laravel\Exceptions\RefreshResponseException;
use Doxa\Laravel\Http\ManagedDispatchOutcome;
use Doxa\Laravel\Http\ManagedHttpPolicy;
use Doxa\Laravel\Http\ManagedTransportResult;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Tests\Fixtures\TraceArguments;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ManagedHttpPolicyTest extends TestCase
{
    private function policy(): ManagedHttpPolicy
    {
        return new ManagedHttpPolicy(new DoxaConfig(Harness::config()));
    }

    public function test_policy_export_cannot_reach_client_secret_sentinel(): void
    {
        $secret = 'CLIENT_SECRET_SENTINEL_DO_NOT_LEAK';
        $policy = new ManagedHttpPolicy(new DoxaConfig([...Harness::config(), 'client_secret' => $secret, 'timeout' => 7]));
        self::assertSame(7, $policy->options()['timeout']);
        self::assertSame(7, $policy->options()['connect_timeout']);
        self::assertSame(7, $policy->options()['read_timeout']);
        self::assertStringNotContainsString($secret, var_export($policy, true));
        self::assertStringNotContainsString($secret, print_r($policy, true));
        self::assertStringNotContainsString($secret, TraceArguments::inspect($policy));
        self::assertStringNotContainsString($secret, json_encode($policy, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($secret, serialize($policy));
    }

    public function test_successful_result_exports_cannot_reach_credential_sentinels(): void
    {
        $json = '{"access_token":"ACCESS_SENTINEL_DO_NOT_LEAK","refresh_token":"REFRESH_SENTINEL_DO_NOT_LEAK","token_type":"Bearer","expires_in":900}';
        $result = $this->policy()->inspectRefresh(new Response(200, ['Content-Type' => 'application/json'], $json), microtime(true), 'OLD_SENTINEL_DO_NOT_LEAK', ['issuer' => Harness::ISSUER, 'client' => 'client', 'subject' => 'sub',
            'scopes' => ['openid'], 'scope_ceiling' => ['openid'], 'absolute_expires_at' => time() + 3600], time());
        $value = $result->validatedRefresh();
        self::assertSame(['openid'], $value->metadata()['scopes']);
        foreach ([$result, $value] as $object) {
            foreach (['ACCESS_SENTINEL_DO_NOT_LEAK', 'REFRESH_SENTINEL_DO_NOT_LEAK', 'OLD_SENTINEL_DO_NOT_LEAK'] as $secret) {
                self::assertStringNotContainsString($secret, var_export($object, true));
                self::assertStringNotContainsString($secret, print_r($object, true));
                self::assertStringNotContainsString($secret, TraceArguments::inspect($object));
            }
            foreach ([fn () => json_encode($object, JSON_THROW_ON_ERROR), fn () => serialize($object)] as $serialize) {
                try {
                    $serialize();
                    self::fail('Protected protocol value serialized');
                } catch (\LogicException $error) {
                    self::assertStringNotContainsString('SENTINEL', (string) $error);
                }
            }
        }
    }

    public function test_inspection_only_result_cannot_retain_or_later_parse_a_raw_body(): void
    {
        $json = '{"access_token":"ACCESS_SENTINEL_DO_NOT_LEAK","refresh_token":"REFRESH_SENTINEL_DO_NOT_LEAK","token_type":"Bearer","expires_in":900}';
        $result = $this->policy()->inspect(new Response(200, ['Content-Type' => 'application/json'], $json), microtime(true));
        self::assertSame(200, $result->status);
        self::assertNull($result->failure);
        self::assertStringNotContainsString('SENTINEL', var_export($result, true));
        self::assertStringNotContainsString('SENTINEL', TraceArguments::inspect($result));
        $this->expectException(RefreshResponseException::class);
        $result->validatedRefresh();
    }

    public function test_malformed_refresh_leaves_no_raw_body_in_result_or_exceptions(): void
    {
        $bodies = [
            '{"access_token":"ACCESS_SENTINEL_DO_NOT_LEAK","refresh_token":"REFRESH_SENTINEL_DO_NOT_LEAK"',
            '{"access_token":"ACCESS_SENTINEL_DO_NOT_LEAK","refresh_token":"REFRESH_SENTINEL_DO_NOT_LEAK","token_type":"Bearer","expires_in":900,"id_token":"ID_SENTINEL_DO_NOT_LEAK"}',
            '{"access_token":"ACCESS_SENTINEL_DO_NOT_LEAK","refresh_token":"OLD_SENTINEL_DO_NOT_LEAK","token_type":"Bearer","expires_in":900}',
        ];
        foreach ($bodies as $json) {
            $response = new Response(200, ['Content-Type' => 'application/json'], $json);
            $result = $this->policy()->inspectRefresh($response, microtime(true), 'OLD_SENTINEL_DO_NOT_LEAK',
                ['issuer' => Harness::ISSUER, 'client' => 'client', 'subject' => 'sub', 'scopes' => ['openid'],
                    'scope_ceiling' => ['openid'], 'absolute_expires_at' => time() + 3600], time());
            self::assertSame(ManagedDispatchOutcome::ResponseReceived, $result->outcome);
            self::assertSame(RefreshFailure::ContractViolation, $result->failure);
            self::assertFalse($response->getBody()->isReadable());
            self::assertStringNotContainsString('SENTINEL', var_export($result, true));
            self::assertStringNotContainsString('SENTINEL', TraceArguments::inspect($result));
            try {
                $result->validatedRefresh();
                self::fail('Malformed refresh was accepted');
            } catch (RefreshResponseException $error) {
                self::assertSame(RefreshFailure::ContractViolation, $error->failure);
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString('SENTINEL', (string) $error);
                self::assertStringNotContainsString('SENTINEL', var_export($error, true));
                self::assertStringNotContainsString('SENTINEL', TraceArguments::inspect($error->getTrace()));
            }
        }
    }

    public function test_policy_has_fixed_options_and_no_client_or_send_method(): void
    {
        self::assertSame(['verify' => true, 'timeout' => 10, 'connect_timeout' => 10, 'read_timeout' => 10,
            'allow_redirects' => false, 'http_errors' => false, 'stream' => true, 'debug' => false,
            'cookies' => false, 'idn_conversion' => false], $this->policy()->options());
        $class = new \ReflectionClass(ManagedHttpPolicy::class);
        self::assertSame(['__construct', 'options', 'inspect', 'inspectRefresh'], array_map(fn ($m) => $m->name, $class->getMethods(\ReflectionMethod::IS_PUBLIC)));
        self::assertSame(['timeout'], array_map(fn ($p) => $p->name, $class->getProperties()));
    }

    public function test_received_response_is_validated_without_a_request(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json; charset=UTF-8'],
            '{"access_token":"ACCESS-SECRET","refresh_token":"NEW-SECRET","token_type":"bearer","expires_in":900}');
        $result = $this->policy()->inspectRefresh($response, microtime(true), 'OLD-SECRET', ['issuer' => Harness::ISSUER, 'client' => 'client', 'subject' => 'sub',
            'scopes' => ['openid'], 'scope_ceiling' => ['openid'], 'absolute_expires_at' => time() + 3600], time());
        self::assertSame(ManagedDispatchOutcome::ResponseReceived, $result->outcome);
        self::assertSame(200, $result->status);
        self::assertNull($result->failure);
        $value = $result->validatedRefresh();
        self::assertSame(['openid'], $value->metadata()['scopes']);
        self::assertStringNotContainsString('SECRET', print_r($result, true));
        self::assertFalse($response->getBody()->isReadable());
    }

    public static function failures(): array
    {
        return [
            [302, ['Location' => 'https://attacker.test'], 'SECRET', RefreshFailure::ContractViolation],
            [307, ['Content-Type' => 'application/json'], '{}', RefreshFailure::ContractViolation],
            [200, ['Content-Type' => 'text/html'], 'SECRET', RefreshFailure::ContractViolation],
            [200, ['Content-Type' => 'application/jwk-set+json'], '{}', RefreshFailure::ContractViolation],
            [200, ['Content-Type' => 'application/json', 'Content-Length' => '500'], '{}', RefreshFailure::ContractViolation],
            [200, ['Content-Type' => 'application/json', 'Content-Length' => 'bad'], '{}', RefreshFailure::ContractViolation],
            [200, ['Content-Type' => 'application/json'], str_repeat('x', 262145), RefreshFailure::ContractViolation],
            [400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant","error_description":"SECRET"}', RefreshFailure::CredentialInvalid],
            [401, ['Content-Type' => 'application/json'], '{"error":"invalid_client","error_description":"SECRET"}', RefreshFailure::ClientUnusable],
            [403, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}', RefreshFailure::ContractViolation],
            [400, ['Content-Type' => 'application/json'], 'SECRET', RefreshFailure::ContractViolation],
            [500, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}', RefreshFailure::Uncertain],
            [503, ['Content-Type' => 'text/html'], 'SECRET', RefreshFailure::Uncertain],
        ];
    }

    #[DataProvider('failures')]
    public function test_failures_are_sanitized_and_never_retry_permission(int $status, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] string $body, RefreshFailure $failure): void
    {
        $result = $this->policy()->inspectRefresh(new Response($status, $headers, $body), microtime(true), 'OLD-SECRET',
            ['issuer' => Harness::ISSUER, 'client' => 'client', 'subject' => 'sub', 'scopes' => ['openid'],
                'scope_ceiling' => ['openid'], 'absolute_expires_at' => time() + 3600], time());
        self::assertSame(ManagedDispatchOutcome::ResponseReceived, $result->outcome);
        self::assertSame($status, $result->status);
        self::assertSame($failure, $result->failure);
        try {
            $result->validatedRefresh();
            self::fail('Failure accepted');
        } catch (RefreshResponseException $error) {
            self::assertSame($failure, $error->failure);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('SECRET', (string) $error);
        }
        self::assertStringNotContainsString('SECRET', print_r($result, true));
        self::assertStringNotContainsString('SECRET', var_export($result, true));
        self::assertStringNotContainsString('SECRET', TraceArguments::inspect($result));
    }

    public function test_deadline_and_broken_stream_are_uncertain_after_headers(): void
    {
        $result = $this->policy()->inspect(new Response(200, ['Content-Type' => 'application/json'], '{}'), microtime(true) - 11);
        self::assertSame(RefreshFailure::Uncertain, $result->failure);
        $stream = new PumpStream(function () {
            throw new \RuntimeException('RESPONSE-SECRET');
        });
        $result = $this->policy()->inspect(new Response(200, ['Content-Type' => 'application/json'], $stream), microtime(true));
        self::assertSame(ManagedDispatchOutcome::ResponseReceived, $result->outcome);
        self::assertSame(RefreshFailure::Uncertain, $result->failure);
        self::assertStringNotContainsString('SECRET', print_r($result, true));
    }

    public function test_argument_enabled_subprocess_traces_never_include_credentials_or_bodies(): void
    {
        foreach (['protocol', 'http', 'direct'] as $mode) {
            $process = new Process([PHP_BINARY, '-d', 'zend.exception_ignore_args=0',
                dirname(__DIR__).'/Fixtures/refresh-response-trace.php', $mode]);
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame(['clean' => true, 'args_enabled' => true, 'previous' => true, 'trace_boundary' => true],
                json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    public function test_certainty_models_are_not_network_dispatch_proof(): void
    {
        self::assertSame(ManagedDispatchOutcome::BeforeDispatch, ManagedTransportResult::beforeDispatch()->outcome);
        self::assertSame(ManagedDispatchOutcome::Uncertain, ManagedTransportResult::uncertain()->outcome);
        $result = ManagedTransportResult::uncertain();
        self::assertFalse(method_exists($result, 'retry'));
        foreach ([fn () => json_encode($result), fn () => serialize($result)] as $serialize) {
            try {
                $serialize();
                self::fail('Protocol result serialized');
            } catch (\LogicException $error) {
                self::assertStringNotContainsString('SECRET', (string) $error);
            }
        }
    }
}
