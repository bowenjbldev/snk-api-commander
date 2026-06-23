<?php

use Illuminate\Support\Facades\Route;
use Packages\Acme\Blog\Http\Controllers\AcmeBlogAuthController;
use Packages\Acme\Blog\Http\Controllers\AcmeBlogUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [AcmeBlogAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AcmeBlogAuthController::class, 'me']);
        Route::post('auth/logout', [AcmeBlogAuthController::class, 'logout']);
        Route::apiResource('users', AcmeBlogUsersController::class);
    });
});

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    // Package business routes here
});

