<?php

declare(strict_types=1);

namespace Doxa\Laravel\Identity;

use Doxa\Laravel\Exceptions\IdTokenValidationException;
use Doxa\Laravel\Exceptions\UserInfoException;
use Doxa\Laravel\Oidc\IdTokenValidator;
use Doxa\Laravel\Oidc\UserInfo;
use Doxa\Laravel\Token\TokenResponse;
use Doxa\Laravel\Transaction\AuthorizationTransaction;

final readonly class DoxaIdentity implements \JsonSerializable
{
    /** @param array<string, string|bool|null> $attributes */
    private function __construct(private string $issuer, private string $subject, private array $attributes) {}

    public static function authenticate(#[\SensitiveParameter] IdTokenValidator $validator, #[\SensitiveParameter] string $token, #[\SensitiveParameter] AuthorizationTransaction $transaction): self
    {
        $claims = $validator->validate($token, $transaction);

        $attributes = self::attributes(array_intersect_key($claims, array_flip(['email', 'email_verified', 'name', 'given_name', 'family_name', 'picture'])));
        if ($attributes === null) {
            throw new IdTokenValidationException('identity_extraction_failure');
        }

        return new self($claims['iss'], $claims['sub'], $attributes);
    }

    public function enriched(#[\SensitiveParameter] UserInfo $userInfo, #[\SensitiveParameter] TokenResponse $tokens, #[\SensitiveParameter] AuthorizationTransaction $transaction): self
    {
        $claims = $userInfo->fetch($this, $tokens, $transaction);
        $attributes = self::attributes(array_intersect_key($claims, array_flip(['email', 'email_verified', 'name', 'given_name', 'family_name', 'picture'])));
        if ($attributes === null) {
            throw new UserInfoException('userinfo_failure');
        }

        // Only supplied allowlisted UserInfo attributes replace current profile values.
        return new self($this->issuer, $this->subject, [...$this->attributes, ...array_filter($attributes, static fn ($value) => $value !== null)]);
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function email(): ?string
    {
        return $this->attributes['email'];
    }

    public function emailVerified(): ?bool
    {
        return $this->attributes['email_verified'];
    }

    public function name(): ?string
    {
        return $this->attributes['name'];
    }

    public function givenName(): ?string
    {
        return $this->attributes['given_name'];
    }

    public function familyName(): ?string
    {
        return $this->attributes['family_name'];
    }

    public function picture(): ?string
    {
        return $this->attributes['picture'];
    }

    public function jsonSerialize(): array
    {
        return ['issuer' => $this->issuer, 'subject' => $this->subject, ...$this->attributes];
    }

    public function __debugInfo(): array
    {
        return ['identity' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Use explicit identity JSON serialization.');
    }

    /** @param array<string, mixed> $claims @return array<string, string|bool|null>|null */
    private static function attributes(#[\SensitiveParameter] array $claims): ?array
    {
        $attributes = [];
        foreach (['email', 'email_verified', 'name', 'given_name', 'family_name', 'picture'] as $field) {
            $value = $claims[$field] ?? null;
            if ($value !== null && ($field === 'email_verified' ? ! is_bool($value) : ! is_string($value))) {
                // Return before constructing an exception: this claims argument never enters its trace.
                return null;
            }
            $attributes[$field] = $value;
        }

        return $attributes;
    }
}
