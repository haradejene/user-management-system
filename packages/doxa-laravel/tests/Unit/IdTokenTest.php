<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Unit;

use Doxa\Laravel\Exceptions\IdTokenValidationException;
use Doxa\Laravel\Identity\DoxaIdentity;
use Doxa\Laravel\Tests\Fixtures\Harness;
use Doxa\Laravel\Transaction\AuthorizationTransaction;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdTokenTest extends TestCase
{
    private Harness $h;

    private AuthorizationTransaction $transaction;

    protected function setUp(): void
    {
        $this->h = new Harness;
        $this->h->begin();
        $this->transaction = $this->h->store->claim($this->h->query['state'], hash('sha256', $this->h->session->getId()));
    }

    protected function tearDown(): void
    {
        $this->h->cleanup();
    }

    public function test_signed_identity_has_only_allowlisted_attributes(): void
    {
        $token = $this->h->jwt(['email' => 'person@example.test', 'email_verified' => false, 'name' => 'Person',
            'given_name' => 'First', 'family_name' => 'Last', 'picture' => 'https://images.test/photo', 'id' => 123,
            'roles' => ['admin'], 'is_system_admin' => true, 'password' => 'PASSWORD']);
        $identity = DoxaIdentity::authenticate($this->h->validator, $token, $this->transaction);
        self::assertSame('public-subject', $identity->subject());
        self::assertSame(Harness::ISSUER, $identity->issuer());
        self::assertSame('person@example.test', $identity->email());
        self::assertFalse($identity->emailVerified());
        self::assertSame('First', $identity->givenName());
        self::assertSame('Last', $identity->familyName());
        self::assertSame('Person', $identity->name());
        self::assertSame('https://images.test/photo', $identity->picture());
        self::assertSame(['issuer', 'subject', 'email', 'email_verified', 'name', 'given_name', 'family_name', 'picture'], array_keys($identity->jsonSerialize()));
        $changed = DoxaIdentity::authenticate($this->h->validator, $this->h->jwt(['email' => 'changed@example.test']), $this->transaction);
        self::assertSame([$identity->issuer(), $identity->subject()], [$changed->issuer(), $changed->subject()]);
        self::assertFalse((new \ReflectionClass(DoxaIdentity::class))->getConstructor()->isPublic());
    }

    public static function invalidClaims(): array
    {
        return [
            'issuer' => [['iss' => Harness::ISSUER.'/'], [], 'invalid_issuer'],
            'audience' => [['aud' => 'other'], [], 'invalid_audience'],
            'audience shape' => [['aud' => 123], [], 'invalid_audience'],
            'empty audience' => [['aud' => []], [], 'invalid_audience'],
            'single azp' => [['azp' => 'other'], [], 'invalid_audience'],
            'multiple missing azp' => [['aud' => ['client-123', 'other']], [], 'invalid_audience'],
            'multiple wrong azp' => [['aud' => ['client-123', 'other'], 'azp' => 'other'], [], 'invalid_audience'],
            'expired' => [['exp' => time() - 1], [], 'expired_id_token'],
            'future iat' => [['iat' => time() + 120], [], 'invalid_id_token'],
            'future iat with nbf' => [['iat' => time() + 120, 'nbf' => time() - 1], [], 'invalid_id_token'],
            'future nbf' => [['nbf' => time() + 120], [], 'invalid_id_token'],
            'nbf shape' => [['nbf' => 'bad'], [], 'invalid_id_token'],
            'temporal order' => [['iat' => time() - 2, 'exp' => time() - 3], [], 'expired_id_token'],
            'missing exp' => [[], ['exp'], 'invalid_id_token'],
            'missing iat' => [[], ['iat'], 'invalid_id_token'],
            'string time' => [['iat' => (string) time()], [], 'invalid_id_token'],
            'nonce' => [['nonce' => 'different'], [], 'invalid_nonce'],
            'missing nonce' => [[], ['nonce'], 'invalid_nonce'],
            'missing subject' => [[], ['sub'], 'invalid_id_token'],
            'empty subject' => [['sub' => ''], [], 'invalid_id_token'],
            'numeric subject' => [['sub' => 123], [], 'invalid_id_token'],
        ];
    }

    #[DataProvider('invalidClaims')]
    public function test_all_required_claim_validation(array $changes, array $remove, string $category): void
    {
        try {
            $this->h->validator->validate($this->h->jwt($changes, $remove), $this->transaction);
            self::fail();
        } catch (IdTokenValidationException $exception) {
            self::assertSame($category, $exception->category);
        }
    }

    public function test_multiple_audience_with_correct_azp_is_valid(): void
    {
        $claims = $this->h->validator->validate($this->h->jwt(['aud' => ['client-123', 'other'], 'azp' => 'client-123']), $this->transaction);
        self::assertSame('public-subject', $claims['sub']);
    }

    public static function attacks(): array
    {
        return ['malformed' => ['malformed'], 'modified' => ['modified'], 'none' => ['none'],
            'HS256 confusion' => ['HS256'], 'RS384' => ['RS384'], 'token key URL' => ['jku'], 'access token' => ['access'], 'wrong signature' => ['signature']];
    }

    #[DataProvider('attacks')]
    public function test_token_attacks_fail(string $attack): void
    {
        $token = $this->h->jwt();
        if ($attack === 'malformed') {
            $token = 'not-a-jwt';
        } elseif ($attack === 'modified') {
            [$head, $body, $sig] = explode('.', $token);
            $token = $head.'.'.rtrim(strtr(base64_encode('{"sub":"attacker"}'), '+/', '-_'), '=').'.'.$sig;
        } elseif ($attack === 'none') {
            $token = rtrim(strtr(base64_encode('{"alg":"none","kid":"key-1"}'), '+/', '-_'), '=').'.e30.';
        } elseif ($attack === 'HS256') {
            $public = openssl_pkey_get_details(openssl_pkey_get_private(Harness::privateKey()))['key'];
            $token = $this->h->jwt([], [], $public, 'HS256');
        } elseif ($attack === 'RS384') {
            $token = $this->h->jwt([], [], null, 'RS384');
        } elseif ($attack === 'jku') {
            $token = JWT::encode(['iss' => Harness::ISSUER], Harness::privateKey(), 'RS256', 'key-1', ['jku' => 'https://attacker.test/key']);
        } elseif ($attack === 'access') {
            $token = $this->h->jwt(['sub' => '123', 'jti' => 'access'], ['nonce']);
        } elseif ($attack === 'signature') {
            [$head, $body] = explode('.', $token);
            $token = $head.'.'.$body.'.'.rtrim(strtr(base64_encode(random_bytes(256)), '+/', '-_'), '=');
        }
        $this->expectException(IdTokenValidationException::class);
        $this->h->validator->validate($token, $this->transaction);
    }

    public function test_host_global_clock_skew_cannot_weaken_sdk(): void
    {
        $leeway = JWT::$leeway;
        $clock = JWT::$timestamp;
        JWT::$leeway = 999999;
        JWT::$timestamp = time() - 1000;
        try {
            $this->expectException(IdTokenValidationException::class);
            $this->h->validator->validate($this->h->jwt(['exp' => time() - 120]), $this->transaction);
        } finally {
            JWT::$leeway = $leeway;
            JWT::$timestamp = $clock;
        }
    }

    public function test_invalid_optional_claim_does_not_produce_identity(): void
    {
        $this->expectException(IdTokenValidationException::class);
        DoxaIdentity::authenticate($this->h->validator, $this->h->jwt(['email_verified' => 'true']), $this->transaction);
    }
}
