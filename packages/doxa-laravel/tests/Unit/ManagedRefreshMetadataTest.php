<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Unit;

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Discovery\ProviderMetadata;
use Doxa\Laravel\Exceptions\DiscoveryException;
use Doxa\Laravel\Tests\Fixtures\Harness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ManagedRefreshMetadataTest extends TestCase
{
    public function test_refresh_capability_is_optional_for_real_login(): void
    {
        $h = new Harness;
        try {
            $h->http->responses[Harness::DISCOVERY] = [...Harness::metadata(), 'grant_types_supported' => ['authorization_code']];
            $h->begin();
            self::assertSame('public-subject', $h->client->handleCallback($h->callback())->subject());
            self::assertSame(1, $h->http->count(Harness::TOKEN));
            $this->expectException(DiscoveryException::class);
            $h->client->discover()->assertManagedRefresh($h->config);
        } finally {
            $h->cleanup();
        }
    }

    public function test_trusted_discovery_can_use_another_https_token_origin(): void
    {
        $config = new DoxaConfig(Harness::config());
        $metadata = ProviderMetadata::fromArray([...Harness::metadata(), 'token_endpoint' => 'https://tokens.example.test/oauth/token'], $config);
        $metadata->assertManagedRefresh($config);
        self::assertSame('https://tokens.example.test/oauth/token', $metadata->tokenEndpoint);
        $this->expectException(DiscoveryException::class);
        $metadata->assertManagedRefresh(new DoxaConfig([...Harness::config(), 'issuer' => 'https://other.example.test']));
    }

    public static function unsafe(): array
    {
        return [['http://localhost/token'], ['ftp://iam.example.test/token'], ['https://user:secret@iam.example.test/token'],
            ['https://iam.example.test/token#fragment']];
    }

    #[DataProvider('unsafe')]
    public function test_unsafe_token_metadata_is_rejected(string $url): void
    {
        $this->expectException(DiscoveryException::class);
        ProviderMetadata::fromArray([...Harness::metadata(), 'token_endpoint' => $url], new DoxaConfig(Harness::config()));
    }

    public function test_missing_grant_evidence_does_not_default_to_refresh_support(): void
    {
        // Legacy serialized metadata has no evidence field and must not gain refresh capability.
        $legacy = (new \ReflectionClass(ProviderMetadata::class))->newInstanceWithoutConstructor();
        try {
            $legacy->assertManagedRefresh(new DoxaConfig(Harness::config()));
            self::fail('Legacy metadata acquired refresh capability');
        } catch (DiscoveryException $error) {
            self::assertSame('unsupported_provider_capability', $error->category);
        }
        $data = Harness::metadata();
        unset($data['grant_types_supported']);
        $this->expectException(DiscoveryException::class);
        ProviderMetadata::fromArray($data, new DoxaConfig(Harness::config()));
    }
}
