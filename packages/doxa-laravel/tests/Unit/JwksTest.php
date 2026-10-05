<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Unit;

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Discovery\ProviderMetadata;
use Doxa\Laravel\Exceptions\JwksException;
use Doxa\Laravel\Tests\Fixtures\Harness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JwksTest extends TestCase
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

    public function test_cache_and_one_refresh_per_window(): void
    {
        $provider = $this->h->client->discover();
        $this->h->jwks->key($provider, 'key-1');
        $this->h->jwks->key($provider, 'key-1');
        self::assertSame(1, $this->h->http->count(Harness::JWKS));
        for ($i = 0; $i < 10; $i++) {
            try {
                $this->h->jwks->key($provider, 'unknown-'.$i);
                self::fail();
            } catch (JwksException) {
            }
        }
        self::assertSame(2, $this->h->http->count(Harness::JWKS));
    }

    public function test_unknown_kid_refresh_can_obtain_current_key(): void
    {
        $provider = $this->h->client->discover();
        $this->h->jwks->key($provider, 'key-1');
        $new = [...Harness::jwk(), 'kid' => 'key-2'];
        $this->h->http->responses[Harness::JWKS] = ['keys' => [$new]];
        self::assertSame('RS256', $this->h->jwks->key($provider, 'key-2')->getAlgorithm());
        self::assertSame(2, $this->h->http->count(Harness::JWKS));
    }

    public function test_cache_expiry_fetches_again(): void
    {
        $h = new Harness(['jwks_cache_ttl' => 1]);
        try {
            $provider = $h->client->discover();
            $h->jwks->key($provider, 'key-1');
            sleep(2);
            $h->jwks->key($provider, 'key-1');
            self::assertSame(2, $h->http->count(Harness::JWKS));
        } finally {
            $h->cleanup();
        }
    }

    public static function invalidKeys(): array
    {
        return [
            'empty' => [[]], 'duplicate' => [[Harness::jwk(), Harness::jwk()]],
            'symmetric' => [[['kid' => 'key-1', 'kty' => 'oct', 'k' => 'AAAA', 'alg' => 'HS256']]],
            'bad RSA' => [[[...Harness::jwk(), 'n' => 'bad']]],
            'encryption use' => [[[...Harness::jwk(), 'use' => 'enc']]],
            'sign operation' => [[[...Harness::jwk(), 'key_ops' => ['sign']]]],
            'wrong algorithm' => [[[...Harness::jwk(), 'alg' => 'HS256']]],
            'private key' => [[[...Harness::jwk(), 'd' => 'SECRET']]],
            'weak modulus' => [[[...Harness::jwk(), 'n' => rtrim(strtr(base64_encode(str_repeat("\xff", 128)), '+/', '-_'), '=')]]],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function test_unusable_or_conflicting_keys_fail(array $keys): void
    {
        $this->h->http->responses[Harness::JWKS] = ['keys' => $keys];
        $this->expectException(JwksException::class);
        $this->h->jwks->key($this->h->client->discover(), 'key-1');
    }

    public function test_issuer_context_mismatch(): void
    {
        $config = new DoxaConfig([...Harness::config(), 'issuer' => 'https://another.test']);
        $provider = ProviderMetadata::fromArray([...Harness::metadata(), 'issuer' => 'https://another.test'], $config);
        $this->expectException(JwksException::class);
        $this->h->jwks->key($provider, 'key-1');
    }

    public function test_failure_never_disables_validation(): void
    {
        $this->h->http->responses[Harness::JWKS] = new \RuntimeException('KEY-SECRET');
        $this->expectException(JwksException::class);
        $this->h->jwks->key($this->h->client->discover(), 'key-1');
    }
}
