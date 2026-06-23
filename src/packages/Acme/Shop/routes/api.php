<?php

use Illuminate\Support\Facades\Route;
use Packages\Acme\Shop\Http\Controllers\AcmeShopAuthController;
use Packages\Acme\Shop\Http\Controllers\AcmeShopUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [AcmeShopAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AcmeShopAuthController::class, 'me']);
        Route::post('auth/logout', [AcmeShopAuthController::class, 'logout']);
        Route::apiResource('users', AcmeShopUsersController::class);
    });
});

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    // Package business routes here
});

