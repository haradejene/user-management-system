<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureCentralIamAdministrator;
use App\Http\Middleware\EnsureOAuthApplicationAccess;
use App\Http\Middleware\OidcAuthorizationTransaction;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->appendToGroup('web', [EnsureAccountIsActive::class.':oauth', OidcAuthorizationTransaction::class]);
        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'central-iam-admin' => EnsureCentralIamAdministrator::class,
            'oauth.iam-access' => EnsureOAuthApplicationAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
