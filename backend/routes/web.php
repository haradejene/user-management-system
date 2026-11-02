<?php

use App\Http\Controllers\Oidc\ApplicationAccessController;
use App\Http\Controllers\Oidc\ChangeAccountController;
use App\Http\Controllers\Oidc\DiscoveryController;
use App\Http\Controllers\Oidc\JwksController;
use App\Http\Controllers\Oidc\SelectAccountController;
use App\Http\Controllers\Oidc\UserInfoController;
use App\Http\Middleware\RequireApplicationAccessContext;
use App\Http\Middleware\RequireOidcBearerToken;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public key publication does not read or create an IAM browser session.
Route::get('/oauth/jwks', JwksController::class)->withoutMiddleware('web')->name('oidc.jwks');

Route::get('/.well-known/openid-configuration', DiscoveryController::class)
    ->withoutMiddleware('web')->name('oidc.discovery');

Route::match(['GET', 'POST'], '/oauth/userinfo', UserInfoController::class)
    ->withoutMiddleware('web')
    ->middleware([RequireOidcBearerToken::class, 'oauth.iam-access'])
    ->name('oidc.userinfo');

Route::get('/oauth/application-access', ApplicationAccessController::class)
    ->withoutMiddleware('web')
    ->middleware([RequireOidcBearerToken::class, RequireApplicationAccessContext::class, 'oauth.iam-access'])
    ->name('oauth.application-access');

Route::get('/', function () {
    return view('welcome');
});

Route::post('/oauth/select-account', SelectAccountController::class)
    ->block(30, 30)->name('oauth.select-account');
Route::get('/oauth/account-choice/{token}', [SelectAccountController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')->name('oauth.account-choice');
Route::post('/oauth/change-account', ChangeAccountController::class)
    ->block(30, 30)->name('oauth.change-account');

Route::get('/login', function (Request $request) {
    $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');
    $intended = $request->session()->get('url.intended');
    $isOAuthContinuation = is_string($intended)
        && parse_url($intended, PHP_URL_PATH) === '/oauth/authorize'
        && parse_url($intended, PHP_URL_HOST) === $request->getHost();

    return redirect()->away($frontend.'/login'.($isOAuthContinuation ? '?oauth_continue=1' : ''));
})->name('login');

Route::get('/oauth/continue', function (Request $request) {
    $intended = $request->session()->pull('url.intended');
    $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

    if (! is_string($intended)
        || parse_url($intended, PHP_URL_PATH) !== '/oauth/authorize'
        || parse_url($intended, PHP_URL_HOST) !== $request->getHost()) {
        return redirect()->away($frontend.'/dashboard');
    }

    return redirect()->to($intended);
});

Route::get('/logout', function (Request $request, AuthService $auth) {
    $auth->logout($request);
    $redirect = $request->query('redirect_uri');
    if ($redirect && filter_var($redirect, FILTER_VALIDATE_URL)) {
        $host = parse_url($redirect, PHP_URL_HOST);
        if (in_array($host, ['localhost', '127.0.0.1'], true)) {
            return redirect()->away($redirect);
        }
    }
    $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

    return redirect()->away($frontend.'/login');
})->name('logout');

