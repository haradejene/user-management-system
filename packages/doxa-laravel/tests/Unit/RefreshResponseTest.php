<?php

declare(strict_types=1);

namespace Doxa\Laravel\Tests\Unit;

use Doxa\Laravel\Exceptions\RefreshFailure;
use Doxa\Laravel\Exceptions\RefreshResponseException;
use Doxa\Laravel\Token\RefreshResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefreshResponseTest extends TestCase
{
    private function binding(): array
    {
        return ['issuer' => 'https://iam.example.test', 'client' => 'client', 'subject' => 'subject',
            'scopes' => ['openid', 'profile'], 'scope_ceiling' => ['openid', 'profile', 'email'],
            'absolute_expires_at' => time() + 3600];
    }

    private function data(): array
    {
        return ['access_token' => 'ACCESS-SECRET', 'refresh_token' => 'NEW-SECRET', 'token_type' => 'Bearer', 'expires_in' => 900];
    }

    public function test_valid_response_has_no_credentials_in_metadata_and_does_not_persist(): void
    {
        $start = time() - 10;
        $binding = $this->binding();
        $value = RefreshResponse::parse(json_encode($this->data()), 'OLD-SECRET', $binding, $start);
        self::assertSame(['scopes' => ['openid', 'profile'], 'access_expires_at' => $start + 900,
            'refresh_expires_at' => null, 'absolute_expires_at' => $binding['absolute_expires_at']], $value->metadata());
        self::assertStringNotContainsString('SECRET', print_r($value, true));
        self::assertStringNotContainsString('SECRET', var_export($value->metadata(), true));
        foreach (['accessToken', 'refreshToken', 'persist', 'send', 'request'] as $method) {
            self::assertFalse(method_exists($value, $method));
        }
    }

    public function test_scope_narrowing_and_exact_optional_binding_metadata(): void
    {
        foreach (['openid', 'profile'] as $scope) {
            $data = [...$this->data(), 'scope' => $scope, 'iss' => 'https://iam.example.test', 'client_id' => 'client', 'sub' => 'subject'];
            $value = RefreshResponse::parse(json_encode($data), 'OLD-SECRET', $this->binding(), time());
            self::assertSame([$scope], $value->metadata()['scopes']);
        }
    }

    public static function invalidFields(): array
    {
        return [
            ['access_token', null], ['access_token', ''], ['access_token', '  '], ['access_token', 7], ['access_token', []],
            ['refresh_token', null], ['refresh_token', ''], ['refresh_token', 1], ['refresh_token', 'OLD-SECRET'],
            ['token_type', null], ['token_type', 'DPoP'], ['token_type', []], ['token_type', ' Bearer'],
            ['expires_in', null], ['expires_in', 0], ['expires_in', -1], ['expires_in', 60], ['expires_in', '900'],
            ['expires_in', 900.5], ['expires_in', true], ['expires_in', 2147483648],
            ['scope', null], ['scope', []], ['scope', ''], ['scope', 'openid  profile'], ['scope', "openid\tprofile"],
            ['scope', 'openid openid'], ['scope', 'openid email'], ['scope', 'offline_access'], ['scope', 'openid "profile"'],
            ['id_token', 'ID-SECRET'], ['id_token', null], ['error', null], ['issuer', 'https://foreign.test'],
            ['iss', null], ['client_id', 'foreign'], ['sub', 1], ['subject', 'foreign'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_fields_are_sanitized(string $field, #[\SensitiveParameter] mixed $value): void
    {
        $this->rejected(json_encode([...$this->data(), $field => $value]));
    }

    public function test_required_fields_and_malformed_json(): void
    {
        foreach (array_keys($this->data()) as $field) {
            $data = $this->data();
            unset($data[$field]);
            $this->rejected(json_encode($data));
        }
        foreach (['{', '[]', 'null', '"SECRET"', '{"access_token":"SECRET"', str_repeat('x', 262145),
            '{"a":'.str_repeat('[', 35).'0'.str_repeat(']', 35).'}'] as $json) {
            $this->rejected($json);
        }
    }

    private function rejected(#[\SensitiveParameter] string $json, #[\SensitiveParameter] ?array $binding = null, ?int $start = null): void
    {
        try {
            RefreshResponse::parse($json, 'OLD-SECRET', $binding ?? $this->binding(), $start ?? time());
            self::fail('Invalid response accepted');
        } catch (RefreshResponseException $error) {
            self::assertSame(RefreshFailure::ContractViolation, $error->failure);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('SECRET', (string) $error);
        }
    }

    public function test_binding_and_conservative_expiry_are_required(): void
    {
        $json = json_encode($this->data());
        $this->rejected($json, start: time() - 850);
        $this->rejected($json, start: time() + 30);
        $this->rejected($json, [...$this->binding(), 'absolute_expires_at' => time() - 1]);
        foreach (array_keys($this->binding()) as $field) {
            $binding = $this->binding();
            unset($binding[$field]);
            $this->rejected($json, $binding);
        }
        $this->rejected($json, [...$this->binding(), 'scopes' => ['openid', 'offline_access']]);
        $this->rejected($json, [...$this->binding(), 'scope_ceiling' => ['profile']]);
    }

    public function test_margin_is_bounded_and_does_not_extend_access_or_context_expiry(): void
    {
        $start = time();
        $binding = [...$this->binding(), 'absolute_expires_at' => $start + 20];
        $json = json_encode([...$this->data(), 'expires_in' => 30]);
        $value = RefreshResponse::parse($json, 'OLD-SECRET', $binding, $start, 0);
        self::assertSame($start + 30, $value->metadata()['access_expires_at']);
        self::assertSame($start + 20, $value->metadata()['absolute_expires_at']);
        foreach ([-1, 61] as $margin) {
            try {
                RefreshResponse::parse($json, 'OLD-SECRET', $binding, $start, $margin);
                self::fail('Unbounded margin accepted');
            } catch (RefreshResponseException $error) {
                self::assertSame(RefreshFailure::ContractViolation, $error->failure);
            }
        }
    }

    public function test_serialization_is_prohibited(): void
    {
        $value = RefreshResponse::parse(json_encode($this->data()), 'OLD-SECRET', $this->binding(), time());
        foreach ([fn () => serialize($value), fn () => json_encode($value)] as $serialize) {
            try {
                $serialize();
                self::fail('Credentials serialized');
            } catch (\LogicException $error) {
                self::assertStringNotContainsString('SECRET', (string) $error);
            }
        }
    }
}
