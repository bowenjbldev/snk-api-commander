<?php

use Illuminate\Support\Facades\Route;
use Packages\Snk\Folio\Http\Controllers\SnkFolioAuthController;
use Packages\Snk\Folio\Http\Controllers\SnkFolioAuthorsController;
use Packages\Snk\Folio\Http\Controllers\SnkFolioCategoriesController;
use Packages\Snk\Folio\Http\Controllers\SnkFolioCommentsController;
use Packages\Snk\Folio\Http\Controllers\SnkFolioLayoutsController;
use Packages\Snk\Folio\Http\Controllers\SnkFolioMediaController;
use Packages\Snk\Folio\Http\Controllers\SnkFolioTagsController;
use Packages\Snk\Folio\Http\Controllers\SnkFolioPostsController;
use Packages\Snk\Folio\Http\Controllers\SnkFolioPublicController;
use Packages\Snk\Folio\Http\Controllers\SnkFolioSettingsController;
use Packages\Snk\Folio\Http\Controllers\SnkFolioSubscribersController;
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
    Route::get('public/categories', [SnkFolioPublicController::class, 'categories']);
    Route::get('public/posts/{slug}', [SnkFolioPublicController::class, 'post']);
    Route::get('public/posts/{slug}/comments', [SnkFolioPublicController::class, 'comments']);
    Route::get('public/layouts/{layout}', [SnkFolioPublicController::class, 'layout'])->whereIn('layout', ['front', 'section']);
    Route::get('public/site', [SnkFolioPublicController::class, 'site']);
    Route::post('public/subscribe', [SnkFolioPublicController::class, 'subscribe'])->middleware('throttle:10,1');
});

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    Route::apiResource('posts', SnkFolioPostsController::class);
    Route::get('posts/{post}/revisions', [SnkFolioPostsController::class, 'revisions']);
    Route::post('posts/{post}/revisions/{revision}/restore', [SnkFolioPostsController::class, 'restore']);
    Route::apiResource('categories', SnkFolioCategoriesController::class);
    Route::apiResource('authors', SnkFolioAuthorsController::class);
    Route::post('media/upload', [SnkFolioMediaController::class, 'upload']);
    Route::apiResource('media', SnkFolioMediaController::class);
    Route::apiResource('tags', SnkFolioTagsController::class);
    Route::apiResource('comments', SnkFolioCommentsController::class);

    Route::apiResource('subscribers', SnkFolioSubscribersController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    Route::get('layouts/{layout}/revisions', [SnkFolioLayoutsController::class, 'revisions'])->whereIn('layout', ['front', 'section']);
    Route::post('layouts/{layout}/revisions/{revision}/restore', [SnkFolioLayoutsController::class, 'restore'])->whereIn('layout', ['front', 'section']);
    Route::get('layouts/{layout}', [SnkFolioLayoutsController::class, 'show'])->whereIn('layout', ['front', 'section']);
    Route::put('layouts/{layout}', [SnkFolioLayoutsController::class, 'update'])->whereIn('layout', ['front', 'section']);

    Route::get('settings', [SnkFolioSettingsController::class, 'show']);
    Route::put('settings', [SnkFolioSettingsController::class, 'update']);
});

