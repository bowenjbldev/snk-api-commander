<?php

use Illuminate\Support\Facades\Route;
use Packages\Blog\Http\Controllers\BlogAuthController;
use Packages\Blog\Http\Controllers\BlogAuthorsController;
use Packages\Blog\Http\Controllers\BlogCommentsController;
use Packages\Blog\Http\Controllers\BlogPostsController;
use Packages\Blog\Http\Controllers\BlogUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [BlogAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [BlogAuthController::class, 'me']);
        Route::post('auth/logout', [BlogAuthController::class, 'logout']);
        Route::apiResource('users', BlogUsersController::class);

        // Blog business resources
        Route::apiResource('authors', BlogAuthorsController::class);
        Route::apiResource('posts', BlogPostsController::class);
        Route::apiResource('comments', BlogCommentsController::class);
    });
});

