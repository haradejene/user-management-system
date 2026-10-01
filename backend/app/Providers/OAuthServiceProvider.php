<?php

namespace App\Providers;

use App\Http\Responses\OidcBearerTokenResponse;
use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use App\Repositories\IamRefreshTokenRepository;
use App\Repositories\OidcAuthCodeRepository;
use DateInterval;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository as PassportRefreshTokenRepository;
use Laravel\Passport\Passport;

class OAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Passport::useAuthorizationServerResponseType(new OidcBearerTokenResponse);
        Passport::useClientModel(OAuthClient::class);
        Passport::useAuthCodeModel(OAuthAuthCode::class);
        Passport::$deviceCodeGrantEnabled = false;
        $this->app->bind(PassportRefreshTokenRepository::class, IamRefreshTokenRepository::class);
        $this->app->bind(PassportAuthCodeRepository::class, OidcAuthCodeRepository::class);
    }

    public function boot(): void
    {
        // Lock before StartSession reads: two tabs must not lose pending entries.
        $this->app->booted(function (): void {
            foreach (app('router')->getRoutes() as $route) {
                if ($route->uri() === 'oauth/authorize') {
                    $route->block(30, 30);
                }
            }
        });
        Passport::loadKeysFrom(storage_path());
        Passport::tokensExpireIn(new DateInterval('PT15M'));
        Passport::refreshTokensExpireIn(new DateInterval('P7D'));
        Passport::tokensCan([
            'iam:read' => 'Read central IAM identity',
            'openid' => 'Authenticate the user with OpenID Connect',
            'profile' => 'Read the user profile through OpenID Connect',
            'email' => 'Read the user email through OpenID Connect',
        ]);
        Passport::setDefaultScope([]);
        Passport::authorizationView('oauth.authorize');
    }
}
