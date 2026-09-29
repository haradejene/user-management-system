<?php

namespace App\Providers;

use App\Models\OAuthClient;
use App\Repositories\IamRefreshTokenRepository;
use DateInterval;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Bridge\RefreshTokenRepository as PassportRefreshTokenRepository;
use Laravel\Passport\Passport;

class OAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Passport::useClientModel(OAuthClient::class);
        Passport::$deviceCodeGrantEnabled = false;
        $this->app->bind(PassportRefreshTokenRepository::class, IamRefreshTokenRepository::class);
    }

    public function boot(): void
    {
        Passport::loadKeysFrom(storage_path());
        Passport::tokensExpireIn(new DateInterval('PT15M'));
        Passport::refreshTokensExpireIn(new DateInterval('P7D'));
        Passport::tokensCan(['iam:read' => 'Read central IAM identity']);
        Passport::setDefaultScope([]);
        Passport::authorizationView('oauth.authorize');
    }
}
