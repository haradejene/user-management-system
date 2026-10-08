<?php

use App\Http\Controllers\Applications\ApplicationAccessController;
use App\Http\Controllers\Applications\ApplicationAuditController;
use App\Http\Controllers\Applications\ApplicationController;
use App\Http\Controllers\Applications\ApplicationStatusController;
use App\Http\Controllers\Applications\OAuthClientController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Companies\CompanyController;
use App\Http\Controllers\Companies\CompanyMembershipController;
use App\Http\Controllers\Companies\CompanyStatusController;
use App\Http\Controllers\Users\ProfileController;
use App\Http\Controllers\Users\UserController;
use App\Http\Controllers\Users\UserStatusController;
use Illuminate\Support\Facades\Route;

Route::post('/register', RegisterController::class)->middleware('throttle:register');
Route::post('/login', LoginController::class)->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', LogoutController::class);
    Route::get('/me', MeController::class)->middleware('active');
    Route::get('/profile', [ProfileController::class, 'own'])->middleware('active');
    Route::patch('/profile', [ProfileController::class, 'updateOwn'])->middleware('active');
});

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'active', 'central-iam-admin'])
    ->group(function (): void {
        Route::apiResource('users', UserController::class)->only(['index', 'store', 'show', 'update']);
        Route::get('users/{user:public_id}/profile', [ProfileController::class, 'show']);
        Route::patch('users/{user:public_id}/profile', [ProfileController::class, 'update']);
        Route::patch('users/{user:public_id}/deactivate', [UserStatusController::class, 'deactivate']);
        Route::patch('users/{user:public_id}/suspend', [UserStatusController::class, 'suspend']);
        Route::patch('users/{user:public_id}/reactivate', [UserStatusController::class, 'reactivate']);

        Route::apiResource('companies', CompanyController::class)->only(['index', 'store', 'show', 'update']);
        Route::patch('companies/{company:public_id}/deactivate', [CompanyStatusController::class, 'deactivate']);
        Route::patch('companies/{company:public_id}/reactivate', [CompanyStatusController::class, 'reactivate']);
        Route::get('companies/{company:public_id}/members', [CompanyMembershipController::class, 'index']);
        Route::post('companies/{company:public_id}/members', [CompanyMembershipController::class, 'store']);
        Route::delete('companies/{company:public_id}/members/{user:public_id}', [CompanyMembershipController::class, 'destroy']);
        Route::get('users/{user:public_id}/companies', [CompanyMembershipController::class, 'forUser']);

        Route::apiResource('applications', ApplicationController::class)->only(['index', 'store', 'show', 'update']);
        Route::patch('applications/{application:public_id}/deactivate', [ApplicationStatusController::class, 'deactivate']);
        Route::patch('applications/{application:public_id}/activate', [ApplicationStatusController::class, 'activate']);
        Route::get('applications/{application:public_id}/oauth-clients', [OAuthClientController::class, 'index']);
        Route::get('applications/{application:public_id}/oauth-clients/{client}', [OAuthClientController::class, 'show']);
        Route::post('applications/{application:public_id}/oauth-clients', [OAuthClientController::class, 'store']);
        Route::patch('applications/{application:public_id}/oauth-clients/{client}/redirect-uris', [OAuthClientController::class, 'updateRedirects']);
        Route::patch('applications/{application:public_id}/oauth-clients/{client}/revoke', [OAuthClientController::class, 'revoke']);
        Route::get('users/{user:public_id}/applications', [ApplicationAccessController::class, 'forUser']);
        Route::post('users/{user:public_id}/applications', [ApplicationAccessController::class, 'store']);
        Route::delete('users/{user:public_id}/applications/{application:public_id}', [ApplicationAccessController::class, 'destroy']);
        Route::get('applications/{application:public_id}/users', [ApplicationAccessController::class, 'forApplication']);
        Route::get('applications/{application:public_id}/history', ApplicationAuditController::class);
    });
