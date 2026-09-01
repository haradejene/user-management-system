<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

Route::post('/register', RegisterController::class);
Route::post('/login', LoginController::class)->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', LogoutController::class);
    Route::get('/me', MeController::class)->middleware('active');
});
