<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureCentralIamAdministrator;
use App\Http\Middleware\EnsureOAuthApplicationAccess;
use App\Http\Middleware\OidcAuthorizationTransaction;
use App\Http\Middleware\SelectOAuthAccount;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The local TLS proxy preserves Host and connects over loopback (IPv4 or IPv6).
        $middleware->trustProxies(at: ['127.0.0.1', '::1'], headers: Request::HEADER_X_FORWARDED_PROTO);
        $middleware->trimStrings(except: ['redirect_uris.*', 'prompt']);
        $middleware->statefulApi();
        $middleware->validateCsrfTokens(except: ['api/logout', 'api/switch-account']);
        $middleware->appendToGroup('web', [EnsureAccountIsActive::class.':oauth', SelectOAuthAccount::class, OidcAuthorizationTransaction::class]);
        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'central-iam-admin' => EnsureCentralIamAdministrator::class,
            'oauth.iam-access' => EnsureOAuthApplicationAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
