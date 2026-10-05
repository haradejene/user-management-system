<?php

declare(strict_types=1);

namespace Doxa\Laravel\Oidc;

use Doxa\Laravel\Contracts\TransactionStore;
use Doxa\Laravel\Exceptions\IdTokenValidationException;
use Doxa\Laravel\Transaction\AuthorizationTransaction;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

final class IdTokenValidator
{
    public function __construct(private readonly JwksProvider $jwks, private readonly TransactionStore $transactions) {}

    /** @return array<string, mixed> */
    public function validate(#[\SensitiveParameter] string $token, #[\SensitiveParameter] AuthorizationTransaction $transaction): array
    {
        $this->transactions->assertClaimed($transaction);
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3 || strlen($token) > 65536) {
                throw new IdTokenValidationException('invalid_id_token');
            }
            foreach ($parts as $part) {
                if (preg_match('/^[A-Za-z0-9_-]+$/D', $part) !== 1) {
                    throw new IdTokenValidationException('invalid_id_token');
                }
            }
            $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/'), true) ?: '', true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)
                || isset($header['crit']) || isset($header['b64']) || isset($header['jku']) || isset($header['x5u']) || isset($header['jwk'])) {
                throw new IdTokenValidationException('invalid_id_token');
            }
            $key = $this->jwks->key($transaction->provider, $header['kid']);
            // This is the library's cryptographic verify operation, not just JWT decode.
            $claims = (array) StrictJwt::decode($token, $key);
            if (($claims['iss'] ?? null) !== $transaction->issuer) {
                throw new IdTokenValidationException('invalid_issuer');
            }
            $aud = $claims['aud'] ?? null;
            $audiences = is_string($aud) ? [$aud] : $aud;
            if (! is_array($audiences) || ! array_is_list($audiences) || $audiences === []) {
                throw new IdTokenValidationException('invalid_audience');
            }
            foreach ($audiences as $audience) {
                if (! is_string($audience) || $audience === '') {
                    throw new IdTokenValidationException('invalid_audience');
                }
            }
            if (! in_array($transaction->clientId, $audiences, true)
                || (count($audiences) > 1 && ($claims['azp'] ?? null) !== $transaction->clientId)
                || (array_key_exists('azp', $claims) && $claims['azp'] !== $transaction->clientId)) {
                throw new IdTokenValidationException('invalid_audience');
            }
            foreach (['exp', 'iat'] as $field) {
                if (! is_int($claims[$field] ?? null)) {
                    throw new IdTokenValidationException('invalid_id_token');
                }
            }
            // Zero skew is a documented stricter policy within the contract's 60s maximum.
            if ($claims['exp'] <= time()) {
                throw new IdTokenValidationException('expired_id_token');
            }
            if ($claims['iat'] > time() || $claims['iat'] < 0 || $claims['exp'] <= $claims['iat']
                || (array_key_exists('nbf', $claims) && (! is_int($claims['nbf']) || $claims['nbf'] > time() || $claims['nbf'] >= $claims['exp']))) {
                throw new IdTokenValidationException('invalid_id_token');
            }
            if (! is_string($claims['nonce'] ?? null) || ! hash_equals($transaction->nonce(), $claims['nonce'])) {
                throw new IdTokenValidationException('invalid_nonce');
            }
            if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
                throw new IdTokenValidationException('invalid_id_token');
            }

            return $claims;
        } catch (IdTokenValidationException $exception) {
            throw $exception;
        } catch (ExpiredException) {
            throw new IdTokenValidationException('expired_id_token');
        } catch (SignatureInvalidException) {
            throw new IdTokenValidationException('invalid_signature');
        } catch (\Throwable) {
            throw new IdTokenValidationException('invalid_id_token');
        }
    }
}
