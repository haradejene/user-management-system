<?php

namespace App\Providers;

use DateInterval;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class OAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Configuration phase only. Enable routes only after IAM policy integration.
        Passport::ignoreRoutes();
    }

    public function boot(): void
    {
        Passport::tokensExpireIn(new DateInterval('PT15M'));
        Passport::refreshTokensExpireIn(new DateInterval('P7D'));
        Passport::tokensCan([]);
        Passport::setDefaultScope([]);
        Passport::$deviceCodeGrantEnabled = false;
    }
}
