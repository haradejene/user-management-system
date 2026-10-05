<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Doxa\Laravel\Authorization\Authorization;
use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Discovery\ProviderMetadata;
use Doxa\Laravel\Exceptions\DoxaException;
use Doxa\Laravel\Http\GuzzleTransport;
use Doxa\Laravel\Identity\DoxaIdentity;
use Doxa\Laravel\Oidc\UserInfo;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Tests\Fixtures\TraceArguments;
use Doxa\Laravel\Token\CodeExchange;
use Doxa\Laravel\Token\TokenResponse;
use Doxa\Laravel\Transaction\CacheTransactionStore;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Encryption\Encrypter;

[$script, $scenario] = $argv;
$h = new Harness(['client_secret' => 'TRACE-CLIENT-SECRET', 'userinfo_enabled' => true]);
try {
    $h->begin();
    $pending = $h->transaction();
    $claims = ['email' => 'trace.private@example.test', 'email_verified' => false, 'name' => 'Trace Private Person',
        'given_name' => 'TraceGiven', 'family_name' => 'TraceFamily', 'picture' => 'https://images.example.test/trace-private',
        'sub' => 'trace-private-subject', 'roles' => ['TRACE-ROLE'], 'internal_id' => 'TRACE-INTERNAL-ID'];
    $h->claimChanges = $claims;
    $jwt = $h->jwt();
    $expiredJwt = $h->jwt(['exp' => time() - 1]);
    $wrongNonceJwt = $h->jwt(['nonce' => 'wrong-nonce']);
    $parts = explode('.', $jwt);
    $wrongSignatureJwt = $parts[0].'.'.$parts[1].'.'.rtrim(strtr(base64_encode(str_repeat('x', 256)), '+/', '-_'), '=');
    $forbidden = [$jwt, $expiredJwt, $wrongNonceJwt, $wrongSignatureJwt, 'ACCESS-SECRET', 'REFRESH-SECRET', 'CODE-SECRET', 'TRACE-CLIENT-SECRET',
        $pending->verifier(), $h->query['code_challenge'], $pending->nonce(), $pending->state,
        'TRACE-NESTED-SECRET', str_repeat('e', 32),
        ...array_filter(array_values($claims), 'is_string'), 'TRACE-ROLE'];
    $nested = new class($forbidden)
    {
        public function __construct(private array $values) {}

        public function __debugInfo(): array
        {
            return ['values' => '[redacted]'];
        }
    };
    $capture = static fn () => ['private' => $nested];
    $h->http->responses[Harness::TOKEN] = ['id_token' => $jwt, 'access_token' => 'ACCESS-SECRET',
        'refresh_token' => 'REFRESH-SECRET', 'token_type' => 'Bearer', 'expires_in' => 900,
        'nested' => ['object' => $nested, 'closure' => $capture]];
    $transaction = null;
    if (! in_array($scenario, ['create', 'claim', 'finish', 'storage', 'authorization', 'metadata', 'callback', 'configuration', 'configuration_url'], true)) {
        $transaction = $h->store->claim($pending->state, $pending->browserBinding());
    }
    try {
        switch ($scenario) {
            case 'validator':
                $h->validator->validate($h->jwt(['aud' => 'wrong-client']), $transaction);
                break;
            case 'validator_expired':
                $h->validator->validate($expiredJwt, $transaction);
                break;
            case 'validator_nonce':
                $h->validator->validate($wrongNonceJwt, $transaction);
                break;
            case 'validator_signature':
                $h->validator->validate($wrongSignatureJwt, $transaction);
                break;
            case 'authenticate':
                DoxaIdentity::authenticate($h->validator, $h->jwt(['email_verified' => ['TRACE-NESTED-SECRET']]), $transaction);
                break;
            case 'userinfo':
                $identity = DoxaIdentity::authenticate($h->validator, $jwt, $transaction);
                $h->http->responses[Harness::USERINFO] = ['sub' => 'other-subject', 'nested' => $capture];
                (new UserInfo($h->http))->fetch($identity, new TokenResponse($jwt, 'ACCESS-SECRET', ['openid']), $transaction);
                break;
            case 'create':
                $h->store->create($pending);
                break;
            case 'claim':
                $h->store->claim($pending->state, 'wrong-browser');
                break;
            case 'finish':
                $h->store->finish($pending->state, false);
                break;
            case 'consume':
                $h->store->consume(clone $transaction);
                break;
            case 'assert':
                $h->store->assertClaimed($pending);
                break;
            case 'finished':
                $h->store->finish($transaction->state, true);
                (new CodeExchange($h->config, $h->http, $h->store))->exchange('CODE-SECRET', $transaction);
                break;
            case 'exchange':
                $h->http->responses[Harness::TOKEN]['expires_in'] = 'invalid';
                (new CodeExchange($h->config, $h->http, $h->store))->exchange('CODE-SECRET', $transaction);
                break;
            case 'replay':
                $exchange = new CodeExchange($h->config, $h->http, $h->store);
                $exchange->exchange('CODE-SECRET', $transaction);
                $exchange->exchange('CODE-SECRET', $transaction);
                break;
            case 'uncertain':
                $h->http->responses[Harness::TOKEN] = new RuntimeException(implode(' ', array_filter($forbidden, 'is_string')));
                (new CodeExchange($h->config, $h->http, $h->store))->exchange('CODE-SECRET', $transaction);
                break;
            case 'authorization':
                $provider = ProviderMetadata::fromArray([...Harness::metadata(), 'token_endpoint' => 'https://other.example.test/token'], $h->config);
                (new Authorization($h->discovery))->create($h->config, $provider, $pending->browserBinding());
                break;
            case 'metadata':
                ProviderMetadata::fromArray(['nested' => $capture], $h->config);
                break;
            case 'storage':
                new CacheTransactionStore(new Repository(new ArrayStore), new Encrypter(str_repeat('e', 32), 'AES-256-CBC'), $h->config);
                break;
            case 'jwks':
                $h->http->responses[Harness::JWKS] = ['keys' => 'invalid', 'nested' => $capture];
                $h->validator->validate($jwt, $transaction);
                break;
            case 'configuration':
                new DoxaConfig([...Harness::config(), 'client_secret' => 'TRACE-CLIENT-SECRET',
                    'timeout' => ['nested' => $capture]]);
                break;
            case 'configuration_url':
                new DoxaConfig([...Harness::config(), 'client_secret' => 'TRACE-CLIENT-SECRET',
                    'issuer' => 'https://TRACE-CLIENT-SECRET@iam.example.test']);
                break;
            case 'transport':
                $stack = HandlerStack::create(new MockHandler([new Response(400, [], implode(' ', array_filter($forbidden, 'is_string')))]));
                (new GuzzleTransport($h->config, new Client(['handler' => $stack])))->request('POST', Harness::TOKEN,
                    ['form_params' => ['code' => 'CODE-SECRET', 'nested' => $capture]]);
                break;
            case 'callback':
                $h->client->handleCallback($h->request('state='.$pending->state.'&code=CODE-SECRET&code=CODE-SECRET'));
                break;
            default:
                throw new RuntimeException('Unknown scenario');
        }
        throw new RuntimeException('Expected SDK error');
    } catch (DoxaException $exception) {
        $recoverable = TraceArguments::inspect(array_column($exception->getTrace(), 'args'), true);
        $recoveredCount = count(array_filter($forbidden, static fn ($value) => str_contains($recoverable, $value)));
        echo json_encode(['ignore_args' => ini_get('zend.exception_ignore_args'), 'class' => $exception::class,
            'protected_parameters' => TraceArguments::protectedParameters($exception->getTrace()),
            'functions' => array_map(static fn ($frame) => ($frame['class'] ?? '').'::'.$frame['function'], $exception->getTrace()),
            'category' => $exception->category, 'message' => $exception->getMessage(), 'previous' => $exception->getPrevious(),
            'arguments' => TraceArguments::inspect(array_column($exception->getTrace(), 'args')),
            'forbidden' => $forbidden, 'recoverable_sensitive_values' => $recoveredCount,
            'positive_control' => TraceArguments::inspect(['array' => ['object' => $nested], 'closure' => $capture])], JSON_THROW_ON_ERROR);
    }
} finally {
    $h->cleanup();
}
