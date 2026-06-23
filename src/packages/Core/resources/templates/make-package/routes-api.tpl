<?php

use Illuminate\Support\Facades\Route;
use Packages\{{namespace}}\Http\Controllers\{{class}}AuthController;
use Packages\{{namespace}}\Http\Controllers\{{class}}UsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [{{class}}AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [{{class}}AuthController::class, 'me']);
        Route::post('auth/logout', [{{class}}AuthController::class, 'logout']);
        Route::apiResource('users', {{class}}UsersController::class);
    });
});

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    // Package business routes here
});
