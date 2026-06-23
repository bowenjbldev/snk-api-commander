<?php

use Illuminate\Support\Facades\Route;
use Packages\Initech\Crm\Http\Controllers\InitechCrmAuthController;
use Packages\Initech\Crm\Http\Controllers\InitechCrmUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [InitechCrmAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [InitechCrmAuthController::class, 'me']);
        Route::post('auth/logout', [InitechCrmAuthController::class, 'logout']);
        Route::apiResource('users', InitechCrmUsersController::class);
    });
});

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    // Package business routes here
});

