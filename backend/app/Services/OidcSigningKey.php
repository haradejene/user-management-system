<?php

namespace App\Services;

use Laravel\Passport\Passport;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use SensitiveParameter;
use Throwable;

class OidcSigningKey
{
    public function publishedKeys(): array
    {
        try {
            // Mirror Passport's inspected key resolution. Derive the public half
            // from the signing key, never from an independently configured public key.
            $key = str_replace('\\n', "\n", config('passport.private_key') ?? '');
            if (! $key) {
                $key = 'file://'.Passport::keyPath('oauth-private.key');
            }

            return ['keys' => [$this->publicJwk(new CryptKey($key, null, Passport::$validateKeyPermissions))]];
        } catch (Throwable) {
            throw OAuthServerException::serverError('The OpenID Connect signing key is unavailable.');
        }
    }

    public function keyId(#[SensitiveParameter] CryptKeyInterface $signingKey): string
    {
        $actual = $this->publicJwk($signingKey);
        $published = $this->publishedKeys()['keys'][0];
        // Fail closed if a long-lived Passport server has an older key loaded.
        // No token should advertise a kid for a key the endpoint cannot publish.
        if (! hash_equals($published['kid'], $actual['kid'])) {
            throw OAuthServerException::serverError('The OpenID Connect signing key is unavailable.');
        }

        return $actual['kid'];
    }

    private function publicJwk(#[SensitiveParameter] CryptKeyInterface $key): array
    {
        $private = @openssl_pkey_get_private($key->getKeyContents(), $key->getPassPhrase() ?? '');
        $details = $private === false ? false : openssl_pkey_get_details($private);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw OAuthServerException::serverError('The OpenID Connect signing key is unavailable.');
        }

        // Whitelist only public RSA parameters; OpenSSL details also contain secrets.
        $n = $this->base64Url(ltrim($details['rsa']['n'], "\0"));
        $e = $this->base64Url(ltrim($details['rsa']['e'], "\0"));
        // RFC 7638: required members only, lexicographically ordered canonical JSON.
        $thumbprint = json_encode(['e' => $e, 'kty' => 'RSA', 'n' => $n], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $this->base64Url(hash('sha256', $thumbprint, true)),
            'n' => $n,
            'e' => $e,
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
