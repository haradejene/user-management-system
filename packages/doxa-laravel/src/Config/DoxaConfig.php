<?php

declare(strict_types=1);

namespace Doxa\Laravel\Config;

use Doxa\Laravel\Exceptions\ConfigurationException;

final class DoxaConfig
{
    public readonly string $issuer;

    public readonly string $clientId;

    private readonly ?string $secret;

    public readonly string $redirectUri;

    /** @var list<string> */
    public readonly array $scopes;

    public readonly int $timeout;

    public readonly int $discoveryTtl;

    public readonly int $jwksTtl;

    public readonly string $authMethod;

    public readonly bool $userInfo;

    /** @param array<string, mixed> $values */
    public function __construct(#[\SensitiveParameter] array $values)
    {
        $required = ['issuer', 'client_id', 'redirect_uri'];
        foreach ($required as $field) {
            if (! is_string($values[$field] ?? null) || trim($values[$field]) === '') {
                throw new ConfigurationException('configuration_error');
            }
        }
        $this->issuer = $values['issuer'];
        $this->clientId = $values['client_id'];
        $this->redirectUri = $values['redirect_uri'];
        self::url($this->issuer);
        self::url($this->redirectUri);
        if (parse_url($this->issuer, PHP_URL_QUERY) !== null || ($values['pkce_required'] ?? true) !== true) {
            throw new ConfigurationException('configuration_error');
        }
        $scopes = $values['scopes'] ?? ['openid'];
        if (! is_array($scopes) || ! array_is_list($scopes) || ! in_array('openid', $scopes, true)) {
            throw new ConfigurationException('configuration_error');
        }
        foreach ($scopes as $scope) {
            if (! is_string($scope) || ! in_array($scope, ['openid', 'profile', 'email', 'iam:read'], true)) {
                throw new ConfigurationException('configuration_error');
            }
        }
        if (count(array_unique($scopes)) !== count($scopes)) {
            throw new ConfigurationException('configuration_error');
        }
        $this->scopes = $scopes;
        $secret = $values['client_secret'] ?? null;
        if ($secret !== null && (! is_string($secret) || $secret === '')) {
            throw new ConfigurationException('configuration_error');
        }
        $this->secret = $secret;
        $method = $values['client_auth_method'] ?? ($secret === null ? 'none' : 'client_secret_basic');
        if (! in_array($method, $secret === null ? ['none'] : ['client_secret_basic', 'client_secret_post'], true)) {
            throw new ConfigurationException('configuration_error');
        }
        $this->authMethod = $method;
        $this->timeout = self::bounded($values['timeout'] ?? 10, 1, 60) ?? throw new ConfigurationException('configuration_error');
        $this->discoveryTtl = self::bounded($values['discovery_cache_ttl'] ?? 300, 1, 3600) ?? throw new ConfigurationException('configuration_error');
        $this->jwksTtl = self::bounded($values['jwks_cache_ttl'] ?? 300, 1, 3600) ?? throw new ConfigurationException('configuration_error');
        if (! is_bool($values['userinfo_enabled'] ?? false)) {
            throw new ConfigurationException('configuration_error');
        }
        $this->userInfo = $values['userinfo_enabled'] ?? false;
    }

    public function secret(): ?string
    {
        return $this->secret;
    }

    public function __debugInfo(): array
    {
        return ['client_id' => $this->clientId, 'client_secret' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new ConfigurationException('configuration_error');
    }

    public static function url(#[\SensitiveParameter] string $url): void
    {
        $parts = parse_url($url);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new ConfigurationException('configuration_error');
        }
    }

    private static function bounded(mixed $value, int $min, int $max): ?int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            return null;
        }

        return $value;
    }
}
