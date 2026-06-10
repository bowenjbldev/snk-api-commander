<?php

use Illuminate\Support\Facades\Route;
use Packages\{{package}}\Http\Controllers\{{package}}AuthController;
use Packages\{{package}}\Http\Controllers\{{package}}UsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [{{package}}AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [{{package}}AuthController::class, 'me']);
        Route::post('auth/logout', [{{package}}AuthController::class, 'logout']);
        Route::apiResource('users', {{package}}UsersController::class);
    });
});

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    // Package business routes here
});
