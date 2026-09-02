<?php

use App\Http\Controllers\Applications\ApplicationController;
use App\Http\Controllers\Applications\ApplicationStatusController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Companies\CompanyController;
use App\Http\Controllers\Companies\CompanyMembershipController;
use App\Http\Controllers\Companies\CompanyStatusController;
use App\Http\Controllers\Users\UserController;
use App\Http\Controllers\Users\UserStatusController;
use Illuminate\Support\Facades\Route;

Route::post('/register', RegisterController::class);
Route::post('/login', LoginController::class)->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', LogoutController::class);
    Route::get('/me', MeController::class)->middleware('active');
});

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'active', 'central-iam-admin'])
    ->group(function (): void {
        Route::apiResource('users', UserController::class)->only(['index', 'store', 'show', 'update']);
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
    });
