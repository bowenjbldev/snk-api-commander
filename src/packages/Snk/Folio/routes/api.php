<?php

use Illuminate\Support\Facades\Route;
use Packages\Snk\Folio\Http\Controllers\SnkFolioAuthController;
use Packages\Snk\Folio\Http\Controllers\SnkFolioLayoutsController;
use Packages\Snk\Folio\Http\Controllers\SnkFolioPostsController;
use Packages\Snk\Folio\Http\Controllers\SnkFolioPublicController;
use Packages\Snk\Folio\Http\Controllers\SnkFolioSettingsController;
use Packages\Snk\Folio\Http\Controllers\SnkFolioUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [SnkFolioAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [SnkFolioAuthController::class, 'me']);
        Route::post('auth/logout', [SnkFolioAuthController::class, 'logout']);
        Route::apiResource('users', SnkFolioUsersController::class);
    });
});

// Lectura pública (sin auth): solo published, resuelta por slug (URLs SEO).
Route::middleware(['api'])->group(function () {
    Route::get('public/posts', [SnkFolioPublicController::class, 'posts']);
    Route::get('public/posts/{slug}', [SnkFolioPublicController::class, 'post']);
    Route::get('public/layouts/{layout}', [SnkFolioPublicController::class, 'layout'])->whereIn('layout', ['portada', 'seccion']);
    Route::get('public/site', [SnkFolioPublicController::class, 'site']);
});

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    Route::apiResource('posts', SnkFolioPostsController::class);

    Route::get('layouts/{layout}', [SnkFolioLayoutsController::class, 'show'])->whereIn('layout', ['portada', 'seccion']);
    Route::put('layouts/{layout}', [SnkFolioLayoutsController::class, 'update'])->whereIn('layout', ['portada', 'seccion']);

    Route::get('settings', [SnkFolioSettingsController::class, 'show']);
    Route::put('settings', [SnkFolioSettingsController::class, 'update']);
});

