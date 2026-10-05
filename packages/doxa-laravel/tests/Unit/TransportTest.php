<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Unit;

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Exceptions\DoxaException;
use Doxa\Laravel\Http\GuzzleTransport;
use Doxa\Laravel\Tests\Fixtures\Harness;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    public function test_isolated_transport_has_safe_options_and_no_logs(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json', 'Cache-Control' => 'public, max-age=300', 'Age' => '10'], '{"ok":true}')]));
        $stack->push(Middleware::history($history));
        $http = new GuzzleTransport(new DoxaConfig(Harness::config()), new Client(['handler' => $stack]));
        $result = $http->request('POST', Harness::TOKEN, ['headers' => ['Authorization' => 'Bearer TOKEN-SECRET'], 'form_params' => ['code' => 'CODE-SECRET']]);
        self::assertSame(['ok' => true], $result->data());
        self::assertSame(290, $result->maxAge);
        self::assertTrue($history[0]['options']['verify']);
        self::assertFalse($history[0]['options']['allow_redirects']);
        self::assertFalse($history[0]['options']['debug']);
        self::assertSame(10, $history[0]['options']['timeout']);
        self::assertSame(Harness::TOKEN, (string) $history[0]['request']->getUri());
        self::assertStringNotContainsString('SECRET', print_r($result, true));
    }

    public static function invalid(): array
    {
        return [
            'redirect' => [302, ['Location' => 'https://attacker.test'], '{}'],
            'denied' => [401, ['Content-Type' => 'application/json'], '{"error":"TOKEN-SECRET"}'],
            'HTML' => [200, ['Content-Type' => 'text/html'], '<html>CODE-SECRET</html>'],
            'malformed' => [200, ['Content-Type' => 'application/json'], '{bad'],
            'array' => [200, ['Content-Type' => 'application/json'], '[]'],
            'large' => [200, ['Content-Type' => 'application/json'], str_repeat('x', 262145)],
        ];
    }

    #[DataProvider('invalid')]
    public function test_failed_response_never_leaks_provider_body(int $status, array $headers, string $body): void
    {
        $http = new GuzzleTransport(new DoxaConfig(Harness::config()), new Client(['handler' => HandlerStack::create(new MockHandler([new Response($status, $headers, $body)]))]));
        try {
            $http->request('POST', Harness::TOKEN);
            self::fail();
        } catch (DoxaException $exception) {
            self::assertStringNotContainsString('SECRET', (string) $exception);
            self::assertNull($exception->getPrevious());
        }
    }

    public function test_connect_failure_is_not_retried(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new ConnectException('CODE-SECRET', new Request('POST', Harness::TOKEN)), new Response(200, ['Content-Type' => 'application/json'], '{}')]));
        $stack->push(Middleware::history($history));
        $http = new GuzzleTransport(new DoxaConfig(Harness::config()), new Client(['handler' => $stack]));
        try {
            $http->request('POST', Harness::TOKEN);
            self::fail();
        } catch (DoxaException) {
        }
        self::assertCount(1, $history);
    }
}
