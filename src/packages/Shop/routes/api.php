<?php

use Illuminate\Support\Facades\Route;
use Packages\Shop\Http\Controllers\ShopAuthController;
use Packages\Shop\Http\Controllers\ShopUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [ShopAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [ShopAuthController::class, 'me']);
        Route::post('auth/logout', [ShopAuthController::class, 'logout']);
        Route::apiResource('users', ShopUsersController::class);
    });
});

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    // Package business routes here
});

