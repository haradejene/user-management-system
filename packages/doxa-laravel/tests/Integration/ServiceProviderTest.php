<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Integration;

use Doxa\Laravel\Authorization\AuthorizationOptions;
use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Contracts\Transport;
use Doxa\Laravel\DoxaClient;
use Doxa\Laravel\DoxaServiceProvider;
use Doxa\Laravel\Exceptions\ConfigurationException;
use Doxa\Laravel\Http\GuzzleTransport;
use Doxa\Laravel\Http\SecureCallbackResponse;
use Doxa\Laravel\Tests\Fixtures\FakeTransport;
use Doxa\Laravel\Tests\Fixtures\Harness;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;

final class ServiceProviderTest extends TestCase
{
    private ?string $cacheDirectory = null;

    protected function getPackageProviders($app): array
    {
        return [DoxaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('doxa', Harness::config());
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('session.driver', 'file');
        $app['config']->set('session.secure', true);
        $app['config']->set('session.http_only', true);
        $app['config']->set('session.same_site', 'lax');
        $app['config']->set('cache.default', 'file');
        $this->cacheDirectory = sys_get_temp_dir().'/doxa-provider-tests-'.bin2hex(random_bytes(12));
        $app['config']->set('cache.stores.file.path', $this->cacheDirectory);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->cacheDirectory !== null) {
                (new Filesystem)->deleteDirectory($this->cacheDirectory);
            }
        }
    }

    public function test_provider_resolves_client_and_publishes_configuration_without_routes(): void
    {
        $fake = new FakeTransport;
        $fake->responses[Harness::DISCOVERY] = Harness::metadata();
        $this->app->instance(Transport::class, $fake);
        $routesBefore = count($this->app['router']->getRoutes());
        $client = $this->app->make(DoxaClient::class);
        self::assertSame(Harness::ISSUER, $client->discover()->issuer);
        self::assertSame($routesBefore, count($this->app['router']->getRoutes()));
        self::assertGuest();
        self::assertNotEmpty(DoxaServiceProvider::pathsToPublish(DoxaServiceProvider::class, 'doxa-config'));
        self::assertSame('session', $this->app['config']->get('auth.guards.web.driver'));
        $this->artisan('package:discover')->assertExitCode(0);
    }

    public function test_http_logging_events_are_not_emitted_by_sdk(): void
    {
        $this->app['events']->listen(RequestSending::class, static function () {
            throw new \RuntimeException('Host HTTP event should not see SDK credentials.');
        });
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{}')]));
        $http = new GuzzleTransport(new DoxaConfig(Harness::config()), new Client(['handler' => $stack]));
        Log::shouldReceive('log')->never();
        self::assertSame([], $http->request('POST', Harness::TOKEN, ['form_params' => ['code' => 'CODE-SECRET']])->data());
    }

    public function test_insecure_session_configuration_is_rejected(): void
    {
        $this->app['config']->set('session.secure', false);
        $this->expectException(ConfigurationException::class);
        $this->app->make(DoxaClient::class);
    }

    public function test_laravel_controller_login_and_callback_keep_authentication_host_owned(): void
    {
        $fake = new FakeTransport;
        $fake->responses[Harness::DISCOVERY] = Harness::metadata();
        $fake->responses[Harness::JWKS] = ['keys' => [Harness::jwk()]];
        $this->app->instance(Transport::class, $fake);
        $this->app['router']->middleware('web')->get('/sdk/login', fn (DoxaClient $client) => $client->beginLogin());
        $this->app['router']->middleware(['web', SecureCallbackResponse::class])->get('/callback',
            fn (Request $request, DoxaClient $client) => response()->json($client->handleCallback($request)));
        $start = $this->get('https://host.example.test/sdk/login');
        $start->assertRedirect();
        parse_str(parse_url($start->headers->get('Location'), PHP_URL_QUERY), $query);
        $h = new Harness;
        try {
            $h->query = $query;
            $fake->responses[Harness::TOKEN] = ['id_token' => $h->jwt(), 'access_token' => 'ACCESS', 'token_type' => 'Bearer', 'expires_in' => 900];
            $cookie = $start->getCookie($this->app['config']->get('session.cookie'), false);
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
            $finish = $this->get('https://host.example.test/callback?state='.$query['state'].'&code=CODE-SECRET');
            $finish->assertOk()->assertJsonPath('subject', 'public-subject')->assertHeader('Referrer-Policy', 'no-referrer');
            self::assertStringContainsString('no-store', $finish->headers->get('Cache-Control'));
            self::assertGuest();
        } finally {
            $h->cleanup();
        }
    }

    public function test_laravel_route_requests_reauthentication_without_accepting_browser_options(): void
    {
        $fake = new FakeTransport;
        $fake->responses[Harness::DISCOVERY] = Harness::metadata();
        $this->app->instance(Transport::class, $fake);
        $this->app['router']->middleware('web')->get('/sdk/reauthenticate',
            fn (DoxaClient $client) => $client->beginLogin(AuthorizationOptions::reauthenticate()));
        $start = $this->get('https://host.example.test/sdk/reauthenticate?prompt=none&state=ATTACKER');
        $start->assertRedirect();
        $url = $start->headers->get('Location');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('login', $query['prompt']);
        self::assertSame(1, preg_match_all('/(?:[?&])prompt=/', $url));
        self::assertNotSame('ATTACKER', $query['state']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertGuest();
    }
}
