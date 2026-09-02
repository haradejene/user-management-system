<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\RegisterController;
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
    });
