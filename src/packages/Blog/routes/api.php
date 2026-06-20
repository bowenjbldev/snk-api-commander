<?php

use Illuminate\Support\Facades\Route;
use Packages\Blog\Http\Controllers\BlogAuthController;
use Packages\Blog\Http\Controllers\BlogUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [BlogAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [BlogAuthController::class, 'me']);
        Route::post('auth/logout', [BlogAuthController::class, 'logout']);
        Route::apiResource('users', BlogUsersController::class);
    });
});

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    // Package business routes here
});

