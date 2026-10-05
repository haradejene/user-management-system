<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Unit;

use Doxa\Laravel\Exceptions\DiscoveryException;
use Doxa\Laravel\Http\JsonResponse;
use Doxa\Laravel\Tests\Fixtures\Harness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscoveryTest extends TestCase
{
    private Harness $h;

    protected function setUp(): void
    {
        $this->h = new Harness;
    }

    protected function tearDown(): void
    {
        $this->h->cleanup();
    }

    public function test_discovery_is_typed_and_cached(): void
    {
        self::assertSame(Harness::ISSUER, $this->h->client->discover()->issuer);
        self::assertSame(Harness::TOKEN, $this->h->client->discover()->tokenEndpoint);
        self::assertSame(1, $this->h->http->count(Harness::DISCOVERY));
    }

    public static function invalid(): array
    {
        return [
            'issuer' => ['issuer', Harness::ISSUER.'/'], 'missing endpoint' => ['jwks_uri', null],
            'HTTP endpoint' => ['token_endpoint', 'http://unsafe.test'], 'response type' => ['response_types_supported', ['token']],
            'grant' => ['grant_types_supported', ['refresh_token']], 'PKCE' => ['code_challenge_methods_supported', ['plain']],
            'algorithm' => ['id_token_signing_alg_values_supported', ['HS256']], 'scopes' => ['scopes_supported', ['email']],
            'auth' => ['token_endpoint_auth_methods_supported', ['private_key_jwt']], 'malformed' => ['scopes_supported', 'openid'],
            'subject' => ['subject_types_supported', ['pairwise']], 'response mode' => ['response_modes_supported', ['fragment']],
        ];
    }

    #[DataProvider('invalid')]
    public function test_discovery_fails_closed(string $field, mixed $value): void
    {
        $this->h->http->responses[Harness::DISCOVERY] = [...Harness::metadata(), $field => $value];
        $this->expectException(DiscoveryException::class);
        $this->h->client->discover();
    }

    public function test_userinfo_endpoint_required_only_if_enabled(): void
    {
        $h = new Harness(['userinfo_enabled' => true]);
        try {
            $data = Harness::metadata();
            unset($data['userinfo_endpoint']);
            $h->http->responses[Harness::DISCOVERY] = $data;
            $this->expectException(DiscoveryException::class);
            $h->client->discover();
        } finally {
            $h->cleanup();
        }
    }

    public function test_provider_no_store_is_honored(): void
    {
        $this->h->http->responses[Harness::DISCOVERY] = new JsonResponse(Harness::metadata(), 0);
        $this->h->client->discover();
        $this->h->client->discover();
        self::assertSame(2, $this->h->http->count(Harness::DISCOVERY));
    }

    public function test_cache_expires(): void
    {
        $h = new Harness(['discovery_cache_ttl' => 1]);
        try {
            $h->client->discover();
            sleep(2);
            $h->client->discover();
            self::assertSame(2, $h->http->count(Harness::DISCOVERY));
        } finally {
            $h->cleanup();
        }
    }
}
