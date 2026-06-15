<?php

use Illuminate\Support\Facades\Route;
use Packages\Commander\Http\Controllers\CommanderActivityController;
use Packages\Commander\Http\Controllers\CommanderAuthController;
use Packages\Commander\Http\Controllers\CommanderClientsController;
use Packages\Commander\Http\Controllers\CommanderDeploymentsController;
use Packages\Commander\Http\Controllers\CommanderInvoicesController;
use Packages\Commander\Http\Controllers\CommanderQuotasController;
use Packages\Commander\Http\Controllers\CommanderSubscriptionsController;
use Packages\Commander\Http\Controllers\CommanderUsageController;
use Packages\Commander\Http\Controllers\CommanderUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [CommanderAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [CommanderAuthController::class, 'me']);
        Route::post('auth/logout', [CommanderAuthController::class, 'logout']);

        // Platform registry
        Route::apiResource('users', CommanderUsersController::class);
        Route::apiResource('clients', CommanderClientsController::class);

        // Signed /control proxy — operator state actions + live mirror sync (push to each deploy)
        Route::post('deployments/{id}/suspend', [CommanderDeploymentsController::class, 'suspend']);
        Route::post('deployments/{id}/grace', [CommanderDeploymentsController::class, 'grace']);
        Route::post('deployments/{id}/resume', [CommanderDeploymentsController::class, 'resume']);
        Route::post('deployments/{id}/sync', [CommanderDeploymentsController::class, 'sync']);
        Route::post('deployments/{id}/rotate-secret', [CommanderDeploymentsController::class, 'rotateSecret']);
        Route::get('deployments/{id}/usage', [CommanderDeploymentsController::class, 'usage']);
        Route::apiResource('deployments', CommanderDeploymentsController::class);
        Route::apiResource('subscriptions', CommanderSubscriptionsController::class);
        Route::apiResource('invoices', CommanderInvoicesController::class);
        Route::apiResource('quotas', CommanderQuotasController::class)->only(['index', 'show', 'update']);
        Route::apiResource('activity', CommanderActivityController::class)->only(['index', 'show']);

        // Platform usage (aggregate of the local snapshot cache) for the Dashboard/Usage charts
        Route::get('usage/platform', [CommanderUsageController::class, 'platform']);
    });
});
