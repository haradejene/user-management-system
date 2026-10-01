<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Date;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

class OidcIdToken
{
    public function __construct(
        private readonly ExchangedOidcNonce $exchange,
        private readonly OidcSigningKey $signingKey,
    ) {}

    public function issue(AccessTokenEntityInterface $accessToken, CryptKeyInterface $privateKey): string
    {
        $nonce = $this->exchange->nonce();
        if ($this->exchange->authorizationCodeId() === null || ! is_string($nonce) || $nonce === '' || strlen($nonce) > 255) {
            throw OAuthServerException::serverError('The OpenID Connect exchange metadata is unavailable.');
        }

        $user = User::query()->with('profile')->find($accessToken->getUserIdentifier());
        $issuer = config('oidc.issuer') ?? config('app.url');
        if (! $user || ! is_string($user->public_id) || $user->public_id === '' || ! is_string($issuer) || $issuer === '') {
            throw OAuthServerException::serverError('The OpenID Connect identity or issuer is unavailable.');
        }

        // Identity, audience and granted scopes come from Passport's issued token
        // entity; the nonce comes exclusively from its completed code exchange.
        $builder = Builder::new(new JoseEncoder, ChainedFormatter::withUnixTimestampDates())
            ->withHeader('kid', $this->signingKey->keyId($privateKey))
            ->issuedBy($issuer)
            ->relatedTo($user->public_id)
            ->permittedFor($accessToken->getClient()->getIdentifier())
            ->issuedAt(Date::now()->toDateTimeImmutable())
            ->expiresAt($accessToken->getExpiryDateTime())
            ->withClaim('nonce', $nonce);
        $scopes = array_map(static fn ($scope): string => $scope->getIdentifier(), $accessToken->getScopes());
        if (in_array('email', $scopes, true)) {
            $builder = $builder->withClaim('email', $user->email)
                ->withClaim('email_verified', $user->email_verified_at !== null);
        }
        if (in_array('profile', $scopes, true)) {
            $claims = [
                'name' => $user->name,
                'given_name' => $user->profile?->first_name,
                'family_name' => $user->profile?->last_name,
                'picture' => $user->profile?->profile_photo,
            ];
            // OIDC picture is an image URL. Do not invent a public URL from a
            // local path or expose storage paths when the photo is not a URL.
            if (! is_string($claims['picture']) || ! filter_var($claims['picture'], FILTER_VALIDATE_URL)
                || ! in_array(parse_url($claims['picture'], PHP_URL_SCHEME), ['http', 'https'], true)) {
                unset($claims['picture']);
            }
            foreach ($claims as $claim => $value) {
                if (is_string($value) && $value !== '') {
                    $builder = $builder->withClaim($claim, $value);
                }
            }
        }

        return $builder->getToken(new Sha256, InMemory::plainText(
            $privateKey->getKeyContents(), $privateKey->getPassPhrase() ?? ''
        ))->toString();
    }
}
