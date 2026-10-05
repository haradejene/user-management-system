<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Integration;

use Doxa\Laravel\Exceptions\ReplayException;
use Doxa\Laravel\Exceptions\TokenExchangeException;
use Doxa\Laravel\Http\JsonResponse;
use Doxa\Laravel\Tests\Fixtures\Harness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExchangeTest extends TestCase
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

    public static function malformed(): array
    {
        return ['empty' => [[]], 'missing id token' => [['access_token' => 'ACCESS']],
            'wrong token type' => [['id_token' => 'ID', 'access_token' => 'ACCESS', 'token_type' => 'mac', 'expires_in' => 900]],
            'same token' => [['id_token' => 'ACCESS', 'access_token' => 'ACCESS', 'token_type' => 'Bearer', 'expires_in' => 900]],
            'scope expansion' => [['id_token' => 'ID', 'access_token' => 'ACCESS', 'token_type' => 'Bearer', 'expires_in' => 900, 'scope' => 'openid admin']],
            'missing openid' => [['id_token' => 'ID', 'access_token' => 'ACCESS', 'token_type' => 'Bearer', 'expires_in' => 900, 'scope' => 'profile']]];
    }

    #[DataProvider('malformed')]
    public function test_malformed_token_response_is_terminal(array $response): void
    {
        $this->h->begin();
        $this->h->http->responses[Harness::TOKEN] = $response;
        try {
            $this->h->client->handleCallback($this->h->callback());
            self::fail();
        } catch (TokenExchangeException) {
        }
        self::assertSame(1, $this->h->http->count(Harness::TOKEN));
        $this->expectException(ReplayException::class);
        $this->h->client->handleCallback($this->h->callback());
    }

    public function test_uncertain_exchange_is_not_retried_and_error_contains_no_secrets(): void
    {
        $this->h->begin();
        $this->h->http->responses[Harness::TOKEN] = new \RuntimeException('CODE-SECRET ACCESS-SECRET');
        try {
            $this->h->client->handleCallback($this->h->callback());
            self::fail();
        } catch (TokenExchangeException $exception) {
            self::assertStringNotContainsString('SECRET', (string) $exception);
            self::assertNull($exception->getPrevious());
        }
        self::assertSame(1, $this->h->http->count(Harness::TOKEN));
        $this->expectException(ReplayException::class);
        $this->h->client->handleCallback($this->h->callback());
    }

    public function test_wrong_token_media_type_is_rejected(): void
    {
        $this->h->begin();
        $this->h->http->responses[Harness::TOKEN] = new JsonResponse(
            ['id_token' => $this->h->jwt(), 'access_token' => 'ACCESS', 'token_type' => 'Bearer', 'expires_in' => 900], null, 'application/jwk-set+json');
        $this->expectException(TokenExchangeException::class);
        $this->h->client->handleCallback($this->h->callback());
    }

    public static function methods(): array
    {
        return [['none'], ['client_secret_basic'], ['client_secret_post']];
    }

    #[DataProvider('methods')]
    public function test_client_authentication_uses_one_method(string $method): void
    {
        $h = new Harness(['client_auth_method' => $method, 'client_secret' => $method === 'none' ? null : 'CLIENT-SECRET']);
        try {
            $query = $h->begin();
            $h->client->handleCallback($h->callback());
            $request = array_values(array_filter($h->http->requests, fn ($r) => $r[1] === Harness::TOKEN))[0];
            self::assertSame('client-123', $request[2]['form_params']['client_id']);
            self::assertSame($method === 'client_secret_post', isset($request[2]['form_params']['client_secret']));
            self::assertSame($method === 'client_secret_basic', isset($request[2]['headers']['Authorization']));
            self::assertArrayNotHasKey('client_secret', $query);
            self::assertStringNotContainsString('SECRET', $request[1]);
        } finally {
            $h->cleanup();
        }
    }
}
