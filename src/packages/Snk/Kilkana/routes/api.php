<?php

use Illuminate\Support\Facades\Route;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaAuthController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaAuthorsController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaCategoriesController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaCommentsController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaLayoutsController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaMediaController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaMediaTaxonomiesController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaTagsController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaPostsController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaPublicController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaSettingsController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaSubscribersController;
use Packages\Snk\Kilkana\Http\Controllers\SnkKilkanaUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [SnkKilkanaAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [SnkKilkanaAuthController::class, 'me']);
        Route::post('auth/logout', [SnkKilkanaAuthController::class, 'logout']);
        Route::apiResource('users', SnkKilkanaUsersController::class);
    });
});

// Lectura pública (sin auth): solo published, resuelta por slug (URLs SEO).
Route::middleware(['api'])->group(function () {
    Route::get('public/posts', [SnkKilkanaPublicController::class, 'posts']);
    Route::get('public/categories', [SnkKilkanaPublicController::class, 'categories']);
    Route::get('public/posts/{slug}', [SnkKilkanaPublicController::class, 'post']);
    Route::get('public/posts/{slug}/comments', [SnkKilkanaPublicController::class, 'comments']);
    Route::get('public/layouts/{layout}', [SnkKilkanaPublicController::class, 'layout'])->whereIn('layout', ['front', 'section']);
    Route::get('public/site', [SnkKilkanaPublicController::class, 'site']);
    Route::post('public/subscribe', [SnkKilkanaPublicController::class, 'subscribe'])->middleware('throttle:10,1');
});

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    Route::apiResource('posts', SnkKilkanaPostsController::class);
    Route::get('posts/{post}/revisions', [SnkKilkanaPostsController::class, 'revisions']);
    Route::post('posts/{post}/revisions/{revision}/restore', [SnkKilkanaPostsController::class, 'restore']);
    Route::apiResource('categories', SnkKilkanaCategoriesController::class);
    Route::apiResource('authors', SnkKilkanaAuthorsController::class);
    Route::post('media/upload', [SnkKilkanaMediaController::class, 'upload']);
    Route::post('media/bulk', [SnkKilkanaMediaController::class, 'bulk']);
    Route::post('media/{id}/restore', [SnkKilkanaMediaController::class, 'restore']);
    Route::delete('media/{id}/force', [SnkKilkanaMediaController::class, 'forceDestroy']);
    Route::post('media/{id}/replace', [SnkKilkanaMediaController::class, 'replace']);
    Route::apiResource('media', SnkKilkanaMediaController::class);
    // Taxonomías de medios: tags y colecciones comparten controlador; el segmento {taxonomy} decide el modelo.
    Route::get('media-{taxonomy}', [SnkKilkanaMediaTaxonomiesController::class, 'index'])->whereIn('taxonomy', ['tags', 'collections']);
    Route::post('media-{taxonomy}', [SnkKilkanaMediaTaxonomiesController::class, 'store'])->whereIn('taxonomy', ['tags', 'collections']);
    Route::put('media-{taxonomy}/{id}', [SnkKilkanaMediaTaxonomiesController::class, 'update'])->whereIn('taxonomy', ['tags', 'collections']);
    Route::delete('media-{taxonomy}/{id}', [SnkKilkanaMediaTaxonomiesController::class, 'destroy'])->whereIn('taxonomy', ['tags', 'collections']);
    Route::apiResource('tags', SnkKilkanaTagsController::class);
    Route::apiResource('comments', SnkKilkanaCommentsController::class);

    Route::apiResource('subscribers', SnkKilkanaSubscribersController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    Route::get('layouts/{layout}/revisions', [SnkKilkanaLayoutsController::class, 'revisions'])->whereIn('layout', ['front', 'section'])->middleware('can:show layouts');
    Route::post('layouts/{layout}/revisions/{revision}/restore', [SnkKilkanaLayoutsController::class, 'restore'])->whereIn('layout', ['front', 'section'])->middleware('can:update layouts');
    Route::get('layouts/{layout}', [SnkKilkanaLayoutsController::class, 'show'])->whereIn('layout', ['front', 'section'])->middleware('can:show layouts');
    Route::put('layouts/{layout}', [SnkKilkanaLayoutsController::class, 'update'])->whereIn('layout', ['front', 'section'])->middleware('can:update layouts');

    Route::get('settings', [SnkKilkanaSettingsController::class, 'show'])->middleware('can:show settings');
    Route::put('settings', [SnkKilkanaSettingsController::class, 'update'])->middleware('can:update settings');
});
