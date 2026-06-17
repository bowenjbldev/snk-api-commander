<?php

use Illuminate\Support\Facades\Route;
use Packages\Shop\Http\Controllers\ShopAuthController;
use Packages\Shop\Http\Controllers\ShopCustomersController;
use Packages\Shop\Http\Controllers\ShopOrdersController;
use Packages\Shop\Http\Controllers\ShopProductsController;
use Packages\Shop\Http\Controllers\ShopUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [ShopAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [ShopAuthController::class, 'me']);
        Route::post('auth/logout', [ShopAuthController::class, 'logout']);
        Route::apiResource('users', ShopUsersController::class);

        // Shop business resources
        Route::apiResource('products', ShopProductsController::class);
        Route::apiResource('customers', ShopCustomersController::class);
        Route::apiResource('orders', ShopOrdersController::class);
    });
});

