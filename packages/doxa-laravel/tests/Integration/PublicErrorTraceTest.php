<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PublicErrorTraceTest extends TestCase
{
    public static function paths(): array
    {
        $paths = ['validator' => 'invalid_audience', 'validator_expired' => 'expired_id_token',
            'validator_nonce' => 'invalid_nonce', 'validator_signature' => 'invalid_signature',
            'authenticate' => 'identity_extraction_failure', 'userinfo' => 'userinfo_failure',
            'create' => 'transaction_storage_failure', 'claim' => 'invalid_state', 'finish' => 'transaction_storage_failure',
            'consume' => 'authorization_error', 'assert' => 'authorization_error', 'finished' => 'transaction_replay',
            'exchange' => 'token_exchange_failure', 'replay' => 'transaction_replay', 'uncertain' => 'token_exchange_failure',
            'authorization' => 'invalid_issuer', 'metadata' => 'invalid_issuer', 'storage' => 'transaction_storage_failure',
            'transport' => 'http_failure', 'callback' => 'authorization_error', 'jwks' => 'invalid_signature',
            'configuration' => 'configuration_error', 'configuration_url' => 'configuration_error',
            'authorization_options' => 'unsupported_authorization_option'];

        return array_map(static fn ($path, $category) => [$path, $category], array_keys($paths), array_values($paths));
    }

    public function test_legitimate_public_parameters_retain_normal_php_wrapper_behavior(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'zend.exception_ignore_args=0',
            dirname(__DIR__).'/Fixtures/public-error-trace.php', 'authenticate']);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertGreaterThan(0, $result['recoverable_sensitive_values']);
        self::assertContains('Doxa\\Laravel\\Identity\\DoxaIdentity::authenticate#1', $result['protected_parameters']);
    }

    #[DataProvider('paths')]
    public function test_public_error_arguments_do_not_expose_sensitive_values(string $path, string $category): void
    {
        $process = new Process([PHP_BINARY, '-d', 'zend.exception_ignore_args=0',
            dirname(__DIR__).'/Fixtures/public-error-trace.php', $path]);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('0', $result['ignore_args']);
        self::assertSame($category, $result['category']);
        self::assertSame('Doxa authentication failed ('.$category.').', $result['message']);
        self::assertNull($result['previous']);
        self::assertNotSame('[]', $result['arguments']);
        foreach (['Doxa\Laravel\Identity\DoxaIdentity::attributes', 'Doxa\Laravel\Config\DoxaConfig::bounded',
            'Doxa\Laravel\Oidc\JwksProvider::parse'] as $helper) {
            self::assertNotContains($helper, $result['functions'], 'Sensitive validation helpers must return before the public exception is constructed');
        }
        $legitimateBoundaries = [
            'Doxa\Laravel\Authorization\AuthorizationOptions::__construct#0',
            'Doxa\Laravel\Identity\DoxaIdentity::authenticate#0', 'Doxa\Laravel\Identity\DoxaIdentity::authenticate#1',
            'Doxa\Laravel\Identity\DoxaIdentity::authenticate#2', 'Doxa\Laravel\Oidc\IdTokenValidator::validate#0',
            'Doxa\Laravel\Oidc\IdTokenValidator::validate#1', 'Doxa\Laravel\Oidc\UserInfo::fetch#0',
            'Doxa\Laravel\Oidc\UserInfo::fetch#1', 'Doxa\Laravel\Oidc\UserInfo::fetch#2',
            'Doxa\Laravel\Transaction\CacheTransactionStore::create#0', 'Doxa\Laravel\Transaction\CacheTransactionStore::claim#0',
            'Doxa\Laravel\Transaction\CacheTransactionStore::claim#1', 'Doxa\Laravel\Transaction\CacheTransactionStore::finish#0',
            'Doxa\Laravel\Transaction\CacheTransactionStore::consume#0', 'Doxa\Laravel\Transaction\CacheTransactionStore::assertClaimed#0',
            'Doxa\Laravel\Transaction\CacheTransactionStore::__construct#0', 'Doxa\Laravel\Transaction\CacheTransactionStore::__construct#1',
            'Doxa\Laravel\Transaction\CacheTransactionStore::__construct#2', 'Doxa\Laravel\Token\CodeExchange::exchange#0',
            'Doxa\Laravel\Token\CodeExchange::exchange#1', 'Doxa\Laravel\Authorization\Authorization::create#0',
            'Doxa\Laravel\Authorization\Authorization::create#2', 'Doxa\Laravel\Discovery\ProviderMetadata::fromArray#0',
            'Doxa\Laravel\Discovery\ProviderMetadata::fromArray#1', 'Doxa\Laravel\Http\GuzzleTransport::request#2',
            'Doxa\Laravel\Authorization\Callback::parse#0', 'Doxa\Laravel\DoxaClient::handleCallback#0',
            'Doxa\Laravel\Config\DoxaConfig::__construct#0', 'Doxa\Laravel\Config\DoxaConfig::url#0',
        ];
        self::assertNotEmpty($result['protected_parameters']);
        self::assertSame([], array_values(array_diff($result['protected_parameters'], $legitimateBoundaries)),
            'Sensitive wrappers must represent legitimate public inputs, not extra internal payload snapshots');
        foreach ($result['forbidden'] as $secret) {
            self::assertStringContainsString($secret, $result['positive_control'], 'Recursive inspector must detect private properties and closure captures');
            self::assertStringNotContainsString($secret, $result['arguments'], $path);
        }
    }
}
