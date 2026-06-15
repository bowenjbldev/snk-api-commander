<?php

use Illuminate\Support\Facades\Route;
use Packages\Cms\Http\Controllers\CmsActivityController;
use Packages\Cms\Http\Controllers\CmsAuthController;
use Packages\Cms\Http\Controllers\CmsClientsController;
use Packages\Cms\Http\Controllers\CmsDeploymentsController;
use Packages\Cms\Http\Controllers\CmsInvoicesController;
use Packages\Cms\Http\Controllers\CmsQuotasController;
use Packages\Cms\Http\Controllers\CmsSubscriptionsController;
use Packages\Cms\Http\Controllers\CmsUsageController;
use Packages\Cms\Http\Controllers\CmsUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [CmsAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [CmsAuthController::class, 'me']);
        Route::post('auth/logout', [CmsAuthController::class, 'logout']);

        // Platform registry
        Route::apiResource('users', CmsUsersController::class);
        Route::apiResource('clients', CmsClientsController::class);

        // Signed /control proxy — operator state actions + live mirror sync (push to each deploy)
        Route::post('deployments/{id}/suspend', [CmsDeploymentsController::class, 'suspend']);
        Route::post('deployments/{id}/grace', [CmsDeploymentsController::class, 'grace']);
        Route::post('deployments/{id}/resume', [CmsDeploymentsController::class, 'resume']);
        Route::post('deployments/{id}/sync', [CmsDeploymentsController::class, 'sync']);
        Route::post('deployments/{id}/rotate-secret', [CmsDeploymentsController::class, 'rotateSecret']);
        Route::get('deployments/{id}/usage', [CmsDeploymentsController::class, 'usage']);
        Route::apiResource('deployments', CmsDeploymentsController::class);
        Route::apiResource('subscriptions', CmsSubscriptionsController::class);
        Route::apiResource('invoices', CmsInvoicesController::class);
        Route::apiResource('quotas', CmsQuotasController::class)->only(['index', 'show', 'update']);
        Route::apiResource('activity', CmsActivityController::class)->only(['index', 'show']);

        // Platform usage (aggregate of the local snapshot cache) for the Dashboard/Usage charts
        Route::get('usage/platform', [CmsUsageController::class, 'platform']);
    });
});
