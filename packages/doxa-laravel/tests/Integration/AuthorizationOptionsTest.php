<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Integration;

use Doxa\Laravel\Authorization\Authorization;
use Doxa\Laravel\Authorization\AuthorizationOptions;
use Doxa\Laravel\Authorization\BrowserContext;
use Doxa\Laravel\DoxaClient;
use Doxa\Laravel\Exceptions\AuthorizationException;
use Doxa\Laravel\Exceptions\DiscoveryException;
use Doxa\Laravel\Exceptions\ReplayException;
use Doxa\Laravel\Oidc\UserInfo;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Token\CodeExchange;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthorizationOptionsTest extends TestCase
{
    private Harness $h;

    protected function setUp(): void
    {
        $this->h = new Harness(['scopes' => ['openid', 'profile']]);
    }

    protected function tearDown(): void
    {
        $this->h->cleanup();
    }

    public function test_no_arguments_preserve_the_complete_existing_authorization_request(): void
    {
        $response = $this->h->client->beginLogin();
        parse_str(parse_url($response->getTargetUrl(), PHP_URL_QUERY), $this->h->query);
        $transaction = $this->h->transaction();
        $parameters = [
            'response_type' => 'code', 'client_id' => $transaction->clientId,
            'redirect_uri' => $transaction->redirectUri, 'scope' => 'openid profile',
            'state' => $transaction->state, 'nonce' => $transaction->nonce(),
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $transaction->verifier(), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];
        self::assertSame($transaction->provider->authorizationEndpoint.'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986), $response->getTargetUrl());
        self::assertArrayNotHasKey('prompt', $this->h->query);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame($transaction->createdAt + 600, $transaction->expiresAt);
        self::assertSame('public-subject', $this->h->client->handleCallback($this->h->callback())->subject());
    }

    public function test_default_options_and_explicit_null_preserve_url_bytes_for_the_same_transaction(): void
    {
        $this->h->begin();
        $authorization = new Authorization($this->h->discovery);
        $transaction = $this->h->transaction();
        $original = $authorization->url($transaction);
        self::assertSame($original, $authorization->url($transaction, null));
        self::assertSame($original, $authorization->url($transaction, new AuthorizationOptions));
    }

    public function test_login_prompt_is_encoded_once_without_changing_any_security_parameter(): void
    {
        $response = $this->h->client->beginLogin(AuthorizationOptions::reauthenticate());
        $url = $response->getTargetUrl();
        parse_str(parse_url($url, PHP_URL_QUERY), $this->h->query);
        $transaction = $this->h->transaction();
        $authorization = new Authorization($this->h->discovery);
        self::assertSame($authorization->url($transaction).'&prompt=login', $url);
        self::assertSame($url, $authorization->url($transaction, new AuthorizationOptions(prompt: 'login')));
        self::assertSame(1, preg_match_all('/(?:[?&])prompt=/', $url));
        self::assertSame('login', $this->h->query['prompt']);
        self::assertStringContainsString('scope=openid%20profile', $url);
        self::assertStringContainsString('redirect_uri=https%3A%2F%2Fhost.example.test%2Fcallback', $url);
        self::assertSame('S256', $this->h->query['code_challenge_method']);
        self::assertSame($transaction->nonce(), $this->h->query['nonce']);
        self::assertSame($transaction->state, $this->h->query['state']);
        self::assertSame(hash('sha256', $this->h->session->getId()), $transaction->browserBinding());
        self::assertSame($transaction->createdAt + 600, $transaction->expiresAt);
        self::assertSame('public-subject', $this->h->client->handleCallback($this->h->callback())->subject());
        self::assertSame(1, $this->h->http->count(Harness::TOKEN));
        $this->expectException(ReplayException::class);
        $this->h->client->handleCallback($this->h->callback());
    }

    public static function unsupportedPrompts(): array
    {
        return array_map(static fn ($prompt) => [$prompt], [
            '', 'none', 'consent', 'select_account', 'login select_account', 'login none', 'LOGIN',
            ' login', 'login ', 'arbitrary', 'login&redirect_uri=https://attacker.test',
            'login%26state%3Dattacker', "login\n", 'SENSITIVE-PROMPT',
        ]);
    }

    #[DataProvider('unsupportedPrompts')]
    public function test_invalid_values_fail_safely_before_discovery_transaction_or_redirect(string $prompt): void
    {
        try {
            $this->h->client->beginLogin(new AuthorizationOptions($prompt));
            self::fail('Unsupported prompt was accepted.');
        } catch (AuthorizationException $exception) {
            self::assertSame('unsupported_authorization_option', $exception->category);
            self::assertSame('Doxa authentication failed (unsupported_authorization_option).', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
        self::assertSame(0, $this->h->http->count(Harness::DISCOVERY));
        self::assertSame(0, $this->h->http->count(Harness::TOKEN));
        self::assertSame([], is_dir($this->h->directory) ? (new Filesystem)->allFiles($this->h->directory) : []);
    }

    public function test_arbitrary_parameter_maps_cannot_be_passed_to_begin_login(): void
    {
        try {
            $this->h->client->beginLogin(['prompt' => 'login', 'state' => 'attacker', 'redirect_uri' => 'https://attacker.test']);
            self::fail('An arbitrary parameter map was accepted.');
        } catch (\TypeError) {
        }
        self::assertSame(0, $this->h->http->count(Harness::DISCOVERY));
    }

    public function test_options_cannot_add_security_parameters_or_be_mutated(): void
    {
        $options = AuthorizationOptions::reauthenticate();
        try {
            $options->prompt = 'none';
            self::fail('Options were mutable.');
        } catch (\Error) {
        }
        try {
            $options->redirect_uri = 'https://attacker.test';
            self::fail('A security parameter was added.');
        } catch (\Error) {
        }
        self::assertSame('login', $options->prompt);
    }

    public function test_unknown_named_authorization_parameters_cannot_be_constructed(): void
    {
        try {
            $this->h->client->beginLogin(new AuthorizationOptions(prompt: 'login', redirect_uri: 'https://attacker.test'));
            self::fail('An arbitrary named parameter was accepted.');
        } catch (\Error) {
        }
        self::assertSame(0, $this->h->http->count(Harness::DISCOVERY));
    }

    public function test_browser_query_cannot_override_explicit_options_or_default_login(): void
    {
        $request = $this->h->request('prompt=none&prompt=select_account&state=ATTACKER&nonce=ATTACKER&client_id=ATTACKER&redirect_uri=https%3A%2F%2Fattacker.test&code_challenge=ATTACKER&code_challenge_method=plain&scope=email');
        $client = new DoxaClient($this->h->config, $this->h->discovery, new Authorization($this->h->discovery), new BrowserContext, $this->h->store,
            new CodeExchange($this->h->config, $this->h->http, $this->h->store), $this->h->validator, new UserInfo($this->h->http), $request);
        parse_str(parse_url($client->beginLogin(AuthorizationOptions::reauthenticate())->getTargetUrl(), PHP_URL_QUERY), $query);
        self::assertSame('login', $query['prompt']);
        self::assertSame('client-123', $query['client_id']);
        self::assertSame($this->h->config->redirectUri, $query['redirect_uri']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('openid profile', $query['scope']);
        foreach (['state', 'nonce', 'code_challenge'] as $key) {
            self::assertNotSame('ATTACKER', $query[$key]);
        }
        parse_str(parse_url($client->beginLogin()->getTargetUrl(), PHP_URL_QUERY), $default);
        self::assertArrayNotHasKey('prompt', $default);
    }

    public function test_callback_prompt_is_not_an_option_or_validation_input(): void
    {
        parse_str(parse_url($this->h->client->beginLogin(AuthorizationOptions::reauthenticate())->getTargetUrl(), PHP_URL_QUERY), $this->h->query);
        $callback = $this->h->request(http_build_query(['state' => $this->h->query['state'], 'code' => 'CODE-SECRET', 'prompt' => 'none']));
        self::assertSame('public-subject', $this->h->client->handleCallback($callback)->subject());
        self::assertSame([], $callback->query->all());
    }

    public function test_provider_query_parameters_remain_rejected_instead_of_duplicating_prompt(): void
    {
        $this->h->http->responses[Harness::DISCOVERY]['authorization_endpoint'] .= '?prompt=none';
        $this->expectException(DiscoveryException::class);
        $this->h->client->beginLogin(AuthorizationOptions::reauthenticate());
    }
}
