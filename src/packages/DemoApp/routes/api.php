<?php

use Illuminate\Support\Facades\Route;
use Packages\Core\Http\Controllers\CoreAuthController;
use Packages\Core\Http\Controllers\CoreHealthController;
use Packages\Core\Http\Controllers\CoreUsersController;

Route::middleware(['api'])->group(function (): void {
    Route::get('/', [CoreHealthController::class, 'index']);
    Route::post('auth/login', [CoreAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('auth/me', [CoreAuthController::class, 'me']);
        Route::post('auth/logout', [CoreAuthController::class, 'logout']);
        Route::apiResource('users', CoreUsersController::class);
    });
});
