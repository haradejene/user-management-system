<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Unit;

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Exceptions\ConfigurationException;
use Doxa\Laravel\Tests\Fixtures\Harness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function test_defaults_are_public_openid_and_basic_for_confidential(): void
    {
        $config = new DoxaConfig(Harness::config());
        self::assertSame('none', $config->authMethod);
        self::assertSame(['openid'], $config->scopes);
        self::assertSame('client_secret_basic', (new DoxaConfig([...Harness::config(), 'client_secret' => 'SECRET']))->authMethod);
    }

    public static function invalid(): array
    {
        return [
            'missing issuer' => [['issuer' => null]], 'missing client' => [['client_id' => '']],
            'missing redirect' => [['redirect_uri' => null]], 'HTTP issuer' => [['issuer' => 'http://iam.test']],
            'issuer credentials' => [['issuer' => 'https://user:pass@iam.test']], 'issuer fragment' => [['issuer' => 'https://iam.test/#x']],
            'issuer query' => [['issuer' => 'https://iam.test/?x=1']], 'bad scopes' => [['scopes' => ['profile']]],
            'email without openid' => [['scopes' => ['email']]], 'offline' => [['scopes' => ['openid', 'offline_access']]],
            'duplicate scopes' => [['scopes' => ['openid', 'openid']]], 'disabled PKCE' => [['pkce_required' => false]],
            'zero timeout' => [['timeout' => 0]], 'unbounded timeout' => [['timeout' => 61]], 'unbounded cache' => [['jwks_cache_ttl' => 999999]],
            'public secret method' => [['client_auth_method' => 'client_secret_post']],
            'confidential none' => [['client_secret' => 'SECRET', 'client_auth_method' => 'none']],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_config_fails_safely(array $override): void
    {
        $this->expectException(ConfigurationException::class);
        new DoxaConfig([...Harness::config(), ...$override]);
    }

    public function test_config_does_not_serialize_secret(): void
    {
        $config = new DoxaConfig([...Harness::config(), 'client_secret' => 'SECRET-DO-NOT-PRINT']);
        self::assertStringNotContainsString('SECRET-DO-NOT-PRINT', json_encode($config));
        self::assertStringNotContainsString('SECRET-DO-NOT-PRINT', print_r($config, true));
        $this->expectException(ConfigurationException::class);
        serialize($config);
    }
}
