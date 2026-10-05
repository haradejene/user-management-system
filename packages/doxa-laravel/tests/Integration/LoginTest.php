<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Integration;

use Doxa\Laravel\Exceptions\CallbackException;
use Doxa\Laravel\Exceptions\DoxaException;
use Doxa\Laravel\Exceptions\ExpiredTransactionException;
use Doxa\Laravel\Exceptions\ProviderDeniedException;
use Doxa\Laravel\Exceptions\ReplayException;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Transaction\AuthorizationTransaction;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LoginTest extends TestCase
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

    public function test_login_returns_validated_identity_without_local_authentication(): void
    {
        $query = $this->h->begin();
        self::assertSame('code', $query['response_type']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame($this->h->config->redirectUri, $query['redirect_uri']);
        self::assertSame('openid', $query['scope']);
        self::assertNotSame($query['state'], $query['nonce']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['state']);
        self::assertArrayNotHasKey('code_verifier', $query);
        self::assertArrayNotHasKey('client_secret', $query);
        $identity = $this->h->client->handleCallback($this->h->callback());
        self::assertSame(Harness::ISSUER, $identity->issuer());
        self::assertSame('public-subject', $identity->subject());
        self::assertNull($identity->email());
        self::assertNull($this->h->session->get('login_web'));
        self::assertSame(1, $this->h->http->count(Harness::TOKEN));
        self::assertSame(0, $this->h->http->count(Harness::USERINFO));
        $tokenRequest = array_values(array_filter($this->h->http->requests, fn ($request) => $request[1] === Harness::TOKEN))[0];
        $verifier = $tokenRequest[2]['form_params']['code_verifier'];
        self::assertSame($query['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
        self::assertSame($query['redirect_uri'], $tokenRequest[2]['form_params']['redirect_uri']);
        self::assertNull($this->h->cache->get('doxa.transaction.'.hash('sha256', $query['state'])));
        $marker = $this->h->cache->get('doxa.transaction.'.hash('sha256', $query['state']).'.claim');
        self::assertSame('succeeded', $marker['status']);
        self::assertStringNotContainsString($query['nonce'], json_encode($marker));
        $this->expectException(ReplayException::class);
        $this->h->client->handleCallback($this->h->callback());
    }

    public static function callbacks(): array
    {
        return [
            'missing state' => ['code=x'], 'unknown state' => ['state='.str_repeat('z', 43).'&code=x'],
            'wrong state' => ['state=wrong&code=x'], 'duplicate code' => ['state={state}&code=a&code=b'],
            'encoded duplicate' => ['state={state}&code=a&%63ode=b'], 'duplicate state' => ['state={state}&state={state}&code=x'],
            'duplicate error' => ['state={state}&error=a&error=b'], 'code and error' => ['state={state}&code=x&error=access_denied'],
            'code array' => ['state={state}&code[]=x'], 'state array' => ['state[]={state}&code=x'],
            'empty code' => ['state={state}&code='], 'no response' => ['state={state}'],
            'bad escape' => ['state={state}&code=%GG'],
        ];
    }

    #[DataProvider('callbacks')]
    public function test_ambiguous_callbacks_never_exchange(string $query): void
    {
        $this->h->begin();
        try {
            $this->h->client->handleCallback($this->h->request(str_replace('{state}', $this->h->query['state'], $query)));
            self::fail('Callback accepted');
        } catch (DoxaException) {
            self::assertSame(0, $this->h->http->count(Harness::TOKEN));
        }
    }

    public function test_wrong_browser_does_not_consume_real_transaction(): void
    {
        $this->h->begin();
        $request = $this->h->callback();
        $other = new Store('test', new ArraySessionHandler(120));
        $other->setId(str_repeat('b', 40));
        $other->start();
        $request->setLaravelSession($other);
        try {
            $this->h->client->handleCallback($request);
            self::fail();
        } catch (DoxaException) {
        }
        self::assertSame(0, $this->h->http->count(Harness::TOKEN));
        self::assertSame('public-subject', $this->h->client->handleCallback($this->h->callback())->subject());
    }

    public function test_provider_denial_is_terminal_and_cleans_secrets(): void
    {
        $this->h->begin();
        try {
            $this->h->client->handleCallback($this->h->request('state='.$this->h->query['state'].'&error=access_denied'));
            self::fail();
        } catch (ProviderDeniedException $exception) {
            self::assertSame('user_denied_authorization', $exception->category);
        }
        self::assertSame(0, $this->h->http->count(Harness::TOKEN));
        self::assertNull($this->h->cache->get('doxa.transaction.'.hash('sha256', $this->h->query['state'])));
        $this->expectException(ReplayException::class);
        $this->h->client->handleCallback($this->h->callback());
    }

    public function test_multiple_attempts_complete_independently(): void
    {
        $first = $this->h->begin();
        $second = $this->h->begin();
        self::assertNotSame($first['state'], $second['state']);
        $this->h->client->handleCallback($this->h->callback());
        $this->h->query = $first;
        self::assertSame('public-subject', $this->h->client->handleCallback($this->h->callback())->subject());
    }

    public function test_callback_query_is_scrubbed_and_nonce_input_cannot_substitute(): void
    {
        $this->h->begin();
        $request = $this->h->request('state='.$this->h->query['state'].'&code=CODE-SECRET&nonce=ATTACKER&redirect_uri=https%3A%2F%2Fattacker.test');
        $request->getRequestUri(); // Simulate URI cached by middleware before callback.
        $identity = $this->h->client->handleCallback($request);
        self::assertSame('public-subject', $identity->subject());
        self::assertSame([], $request->query->all());
        self::assertStringNotContainsString('CODE-SECRET', $request->fullUrl());
        self::assertSame('', $request->server->get('QUERY_STRING'));
        self::assertStringNotContainsString('CODE-SECRET', $request->getRequestUri());
    }

    public function test_missing_browser_session_fails_without_exchange(): void
    {
        $this->h->begin();
        $request = Request::create('https://host.example.test/callback?state='.$this->h->query['state'].'&code=x');
        $this->expectException(CallbackException::class);
        $this->h->client->handleCallback($request);
    }

    public function test_validation_failure_leaves_only_failed_terminal_marker(): void
    {
        $this->h->begin();
        $this->h->claimChanges = ['nonce' => 'ATTACKER'];
        try {
            $this->h->client->handleCallback($this->h->callback());
            self::fail();
        } catch (DoxaException) {
        }
        $key = 'doxa.transaction.'.hash('sha256', $this->h->query['state']);
        self::assertNull($this->h->cache->get($key));
        self::assertSame('failed', $this->h->cache->get($key.'.claim')['status']);
        $this->expectException(ReplayException::class);
        $this->h->client->handleCallback($this->h->callback());
    }

    public function test_expired_transaction_is_rejected(): void
    {
        $query = $this->h->begin();
        $key = 'doxa.transaction.'.hash('sha256', $query['state']);
        $cipher = new Encrypter(str_repeat('k', 32), 'AES-256-CBC');
        $old = $cipher->decrypt($this->h->cache->get($key));
        $expired = new AuthorizationTransaction($old->state, $old->nonce(), $old->verifier(), $old->issuer,
            $old->clientId, $old->redirectUri, $old->scopes, time() - 601, time() - 1, $old->browserBinding(), $old->provider);
        $this->h->cache->put($key, $cipher->encrypt($expired), 60);
        $this->expectException(ExpiredTransactionException::class);
        $this->h->client->handleCallback($this->h->callback());
    }
}
