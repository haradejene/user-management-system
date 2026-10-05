<?php

declare(strict_types=1);

namespace Doxa\Laravel\Discovery;

use Doxa\Laravel\Config\DoxaConfig;
use Doxa\Laravel\Exceptions\DiscoveryException;

final readonly class ProviderMetadata
{
    /** @param list<string> $scopes @param list<string> $authMethods */
    private function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public ?string $userInfoEndpoint,
        public array $scopes,
        public array $authMethods,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(#[\SensitiveParameter] array $data, #[\SensitiveParameter] DoxaConfig $config): self
    {
        try {
            if (($data['issuer'] ?? null) !== $config->issuer) {
                throw new DiscoveryException('invalid_issuer');
            }
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
                if (! is_string($data[$field] ?? null)) {
                    throw new DiscoveryException('discovery_error');
                }
                DoxaConfig::url($data[$field]);
            }
            if (isset($data['userinfo_endpoint'])) {
                if (! is_string($data['userinfo_endpoint'])) {
                    throw new DiscoveryException('discovery_error');
                }
                DoxaConfig::url($data['userinfo_endpoint']);
            }
            if ($config->userInfo && ! isset($data['userinfo_endpoint'])) {
                throw new DiscoveryException('unsupported_provider_capability');
            }
            $requirements = [
                'response_types_supported' => ['code'],
                'response_modes_supported' => ['query'],
                'grant_types_supported' => ['authorization_code'],
                'subject_types_supported' => ['public'],
                'code_challenge_methods_supported' => ['S256'],
                'id_token_signing_alg_values_supported' => ['RS256'],
                'scopes_supported' => $config->scopes,
                'token_endpoint_auth_methods_supported' => [$config->authMethod],
            ];
            foreach ($requirements as $field => $required) {
                $supported = $data[$field] ?? null;
                if (! is_array($supported) || ! array_is_list($supported)) {
                    throw new DiscoveryException('discovery_error');
                }
                foreach ($supported as $value) {
                    if (! is_string($value)) {
                        throw new DiscoveryException('discovery_error');
                    }
                }
                if (array_diff($required, $supported) !== []) {
                    throw new DiscoveryException('unsupported_provider_capability');
                }
            }

            return new self($data['issuer'], $data['authorization_endpoint'], $data['token_endpoint'], $data['jwks_uri'],
                $data['userinfo_endpoint'] ?? null, $data['scopes_supported'], $data['token_endpoint_auth_methods_supported']);
        } catch (DiscoveryException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new DiscoveryException('discovery_error');
        }
    }
}
