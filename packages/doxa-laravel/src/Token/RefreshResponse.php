<?php

declare(strict_types=1);

namespace Doxa\Laravel\Token;

use Doxa\Laravel\Credentials\ProtectedValue;
use Doxa\Laravel\Exceptions\RefreshFailure;
use Doxa\Laravel\Exceptions\RefreshResponseException;
use Doxa\Laravel\Http\ManagedHttpPolicy;

/** Validated metadata only; token values are discarded after validation: no provenance, persistence, dispatch or credential accessor. */
final readonly class RefreshResponse extends ProtectedValue
{
    /** @param list<string> $scopes */
    private function __construct(
        private array $scopes,
        private int $accessExpiresAt,
        private int $absoluteExpiresAt,
    ) {}

    /** @param array<string, mixed> $binding Trusted issuer/client/subject, prior scopes/ceiling and absolute deadline. */
    public static function parse(#[\SensitiveParameter] string $json, #[\SensitiveParameter] string $oldRefreshToken, #[\SensitiveParameter] array $binding, int $startedAt, int $safetyMargin = 60): self
    {
        // Return a sentinel before throwing so sensitive parser locals are not retained in error frames.
        $value = self::validated($json, $oldRefreshToken, $binding, $startedAt, $safetyMargin);
        if ($value === null) {
            throw new RefreshResponseException(RefreshFailure::ContractViolation);
        }

        return $value;
    }

    /** @return array{scopes: list<string>, access_expires_at: int, refresh_expires_at: null, absolute_expires_at: int} */
    public function metadata(): array
    {
        return ['scopes' => $this->scopes, 'access_expires_at' => $this->accessExpiresAt,
            'refresh_expires_at' => null, 'absolute_expires_at' => $this->absoluteExpiresAt];
    }

    /** @param array<string, mixed> $binding */
    private static function validated(#[\SensitiveParameter] string $json, #[\SensitiveParameter] string $oldRefreshToken, #[\SensitiveParameter] array $binding, int $startedAt, int $safetyMargin = 60): ?self
    {
        try {
            if ($safetyMargin < 0 || $safetyMargin > 60 || strlen($json) > ManagedHttpPolicy::MAX_BODY_BYTES || $oldRefreshToken === '' || $startedAt <= 0 || $startedAt > time()) {
                return null;
            }
            $object = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
            if (! $object instanceof \stdClass) {
                return null;
            }
            $data = (array) $object;
            foreach (['access_token', 'refresh_token', 'token_type'] as $field) {
                if (! is_string($data[$field] ?? null) || trim($data[$field]) === '') {
                    return null;
                }
            }
            if (array_key_exists('id_token', $data) || array_key_exists('error', $data)
                || strcasecmp($data['token_type'], 'Bearer') !== 0 || hash_equals($oldRefreshToken, $data['refresh_token'])
                || ! is_int($data['expires_in'] ?? null) || $data['expires_in'] <= 0 || $data['expires_in'] > 2147483647
                || $startedAt + $data['expires_in'] <= time() + $safetyMargin
                || ! is_int($binding['absolute_expires_at'] ?? null) || $binding['absolute_expires_at'] <= time()) {
                return null;
            }
            foreach (['issuer', 'client', 'subject'] as $field) {
                if (! is_string($binding[$field] ?? null) || $binding[$field] === '') {
                    return null;
                }
            }
            foreach (['issuer' => 'issuer', 'iss' => 'issuer', 'client_id' => 'client', 'client' => 'client', 'sub' => 'subject', 'subject' => 'subject'] as $key => $field) {
                if (array_key_exists($key, $data) && $data[$key] !== $binding[$field]) {
                    return null;
                }
            }
            foreach (['scopes', 'scope_ceiling'] as $field) {
                if (! is_array($binding[$field] ?? null) || ! array_is_list($binding[$field])
                    || count(array_unique($binding[$field], SORT_REGULAR)) !== count($binding[$field])) {
                    return null;
                }
                foreach ($binding[$field] as $scope) {
                    if (! is_string($scope) || ! in_array($scope, ['openid', 'profile', 'email', 'iam:read'], true)) {
                        return null;
                    }
                }
            }
            if (! in_array('openid', $binding['scope_ceiling'], true) || array_diff($binding['scopes'], $binding['scope_ceiling']) !== []) {
                return null;
            }
            $scopes = $binding['scopes'];
            if (array_key_exists('scope', $data)) {
                if (! is_string($data['scope']) || preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+(?: [\x21\x23-\x5B\x5D-\x7E]+)*$/D', $data['scope']) !== 1) {
                    return null;
                }
                $scopes = explode(' ', $data['scope']);
                if (count(array_unique($scopes)) !== count($scopes) || array_diff($scopes, $binding['scopes']) !== []) {
                    return null;
                }
            }

            return new self($scopes,
                $startedAt + $data['expires_in'], $binding['absolute_expires_at']);
        } catch (\Throwable) {
            return null;
        }
    }
}
