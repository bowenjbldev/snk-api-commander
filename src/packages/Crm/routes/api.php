<?php

use Illuminate\Support\Facades\Route;
use Packages\Crm\Http\Controllers\CrmAuthController;
use Packages\Crm\Http\Controllers\CrmUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [CrmAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [CrmAuthController::class, 'me']);
        Route::post('auth/logout', [CrmAuthController::class, 'logout']);
        Route::apiResource('users', CrmUsersController::class);
    });
});

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    // Package business routes here
});

