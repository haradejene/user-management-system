<?php

use App\Http\Controllers\Oidc\DiscoveryController;
use App\Http\Controllers\Oidc\JwksController;
use App\Http\Controllers\Oidc\UserInfoController;
use App\Http\Middleware\RequireOidcBearerToken;
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

Route::get('/', function () {
    return view('welcome');
});

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
