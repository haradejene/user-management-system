<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Fixtures;

use Doxa\Laravel\Authorization\Authorization;
use Doxa\Laravel\Authorization\BrowserContext;
use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Discovery\Discovery;
use Doxa\Laravel\DoxaClient;
use Doxa\Laravel\Oidc\IdTokenValidator;
use Doxa\Laravel\Oidc\JwksProvider;
use Doxa\Laravel\Oidc\UserInfo;
use Doxa\Laravel\Token\CodeExchange;
use Doxa\Laravel\Transaction\AuthorizationTransaction;
use Doxa\Laravel\Transaction\CacheTransactionStore;
use Firebase\JWT\JWT;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

final class Harness
{
    public const ISSUER = 'https://iam.example.test';

    public const TOKEN = self::ISSUER.'/oauth/token';

    public const JWKS = self::ISSUER.'/oauth/jwks';

    public const USERINFO = self::ISSUER.'/oauth/userinfo';

    public const DISCOVERY = self::ISSUER.'/.well-known/openid-configuration';

    public DoxaConfig $config;

    public FakeTransport $http;

    public Repository $cache;

    public CacheTransactionStore $store;

    public DoxaClient $client;

    public Discovery $discovery;

    public JwksProvider $jwks;

    public IdTokenValidator $validator;

    public Store $session;

    public string $directory;

    public array $query = [];

    public array $claimChanges = [];

    public array $removedClaims = [];

    public string $kid = 'key-1';

    private static ?string $privateKey = null;

    private static ?array $publicJwk = null;

    public function __construct(array $overrides = [], ?string $directory = null, string $driver = 'file')
    {
        $this->directory = $directory ?? sys_get_temp_dir().'/doxa-tests-'.bin2hex(random_bytes(12));
        $this->config = new DoxaConfig([...self::config(), ...$overrides]);
        if ($driver === 'database') {
            (new Filesystem)->ensureDirectoryExists($this->directory);
            $database = $this->directory.'/cache.sqlite';
            if (! is_file($database)) {
                touch($database);
            }
            $capsule = new Manager;
            $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => '']);
            $connection = $capsule->getConnection();
            $connection->statement('PRAGMA busy_timeout=10000');
            $connection->statement('CREATE TABLE IF NOT EXISTS cache (key TEXT PRIMARY KEY, value TEXT NOT NULL, expiration INTEGER NOT NULL)');
            $this->cache = new Repository(new DatabaseStore($connection, 'cache', 'doxa-tests'));
        } else {
            $this->cache = new Repository(new FileStore(new Filesystem, $this->directory));
        }
        $this->store = new CacheTransactionStore($this->cache, new Encrypter(str_repeat('k', 32), 'AES-256-CBC'), $this->config);
        $this->session = new Store('test', new ArraySessionHandler(120));
        $this->session->setId(str_repeat('a', 40));
        $this->session->start();
        $this->http = new FakeTransport;
        $this->http->responses[self::DISCOVERY] = self::metadata();
        $this->http->responses[self::JWKS] = ['keys' => [self::jwk()]];
        $this->http->responses[self::TOKEN] = function (array $options): array {
            $verifier = $options['form_params']['code_verifier'] ?? '';
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if ($challenge !== ($this->query['code_challenge'] ?? null)) {
                throw new \RuntimeException('PKCE mismatch');
            }

            return ['access_token' => 'ACCESS-SECRET', 'refresh_token' => 'REFRESH-SECRET',
                'id_token' => $this->jwt(), 'token_type' => 'Bearer', 'expires_in' => 900];
        };
        $this->http->responses[self::USERINFO] = ['sub' => 'public-subject', 'name' => 'Updated name'];
        $this->discovery = new Discovery($this->config, $this->http, $this->cache);
        $this->jwks = new JwksProvider($this->config, $this->http, $this->cache);
        $this->validator = new IdTokenValidator($this->jwks, $this->store);
        $this->client = new DoxaClient($this->config, $this->discovery, new Authorization($this->discovery), new BrowserContext, $this->store,
            new CodeExchange($this->config, $this->http, $this->store), $this->validator, new UserInfo($this->http), $this->request());
    }

    public static function config(): array
    {
        return ['issuer' => self::ISSUER, 'client_id' => 'client-123', 'redirect_uri' => 'https://host.example.test/callback',
            'scopes' => ['openid'], 'pkce_required' => true, 'timeout' => 10, 'discovery_cache_ttl' => 300, 'jwks_cache_ttl' => 300];
    }

    public static function metadata(): array
    {
        return ['issuer' => self::ISSUER, 'authorization_endpoint' => self::ISSUER.'/oauth/authorize', 'token_endpoint' => self::TOKEN,
            'jwks_uri' => self::JWKS, 'userinfo_endpoint' => self::USERINFO, 'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'], 'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'], 'code_challenge_methods_supported' => ['S256'],
            'id_token_signing_alg_values_supported' => ['RS256'], 'scopes_supported' => ['openid', 'profile', 'email', 'iam:read'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post']];
    }

    public function begin(): array
    {
        parse_str(parse_url($this->client->beginLogin()->getTargetUrl(), PHP_URL_QUERY), $this->query);

        return $this->query;
    }

    public function request(string $query = ''): Request
    {
        $request = Request::create('https://host.example.test/callback'.($query === '' ? '' : '?'.$query));
        $request->setLaravelSession($this->session);

        return $request;
    }

    public function callback(): Request
    {
        return $this->request(http_build_query(['state' => $this->query['state'], 'code' => 'CODE-SECRET']));
    }

    public function transaction(): AuthorizationTransaction
    {
        return (new Encrypter(str_repeat('k', 32), 'AES-256-CBC'))->decrypt(
            $this->cache->get('doxa.transaction.'.hash('sha256', $this->query['state'])));
    }

    public function jwt(array $changes = [], array $remove = [], ?string $key = null, string $algorithm = 'RS256'): string
    {
        $claims = ['iss' => self::ISSUER, 'sub' => 'public-subject', 'aud' => $this->config->clientId, 'exp' => time() + 900,
            'iat' => time(), 'nonce' => $this->query['nonce'] ?? str_repeat('n', 43)];
        $claims = [...$claims, ...$this->claimChanges, ...$changes];
        foreach ([...$this->removedClaims, ...$remove] as $field) {
            unset($claims[$field]);
        }
        // Sign adversarial claim shapes even when the library encoder rejects them.
        $encode = static fn ($data) => rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $input = $encode(['typ' => 'JWT', 'alg' => $algorithm, 'kid' => $this->kid]).'.'.$encode($claims);

        return $input.'.'.rtrim(strtr(base64_encode(JWT::sign($input, $key ?? self::privateKey(), $algorithm)), '+/', '-_'), '=');
    }

    public static function privateKey(): string
    {
        self::generateKey();

        return self::$privateKey;
    }

    public static function jwk(): array
    {
        self::generateKey();

        return self::$publicJwk;
    }

    private static function generateKey(): void
    {
        if (self::$privateKey !== null) {
            return;
        }
        $options = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => __DIR__.'/openssl.cnf'];
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $pem, null, $options);
        self::$privateKey = $pem;
        $rsa = openssl_pkey_get_details($key)['rsa'];
        self::$publicJwk = ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'key-1',
            'n' => rtrim(strtr(base64_encode($rsa['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($rsa['e']), '+/', '-_'), '=')];
    }

    public function cleanup(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
    }
}
