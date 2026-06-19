<?php

use Illuminate\Support\Facades\Route;
use Packages\Commander\Http\Controllers\CommanderActivityController;
use Packages\Commander\Http\Controllers\CommanderAuthController;
use Packages\Commander\Http\Controllers\CommanderClientsController;
use Packages\Commander\Http\Controllers\CommanderDeploymentsController;
use Packages\Commander\Http\Controllers\CommanderInvoicesController;
use Packages\Commander\Http\Controllers\CommanderPlansController;
use Packages\Commander\Http\Controllers\CommanderProvisioningController;
use Packages\Commander\Http\Controllers\CommanderQuotasController;
use Packages\Commander\Http\Controllers\CommanderSubscriptionsController;
use Packages\Commander\Http\Controllers\CommanderUsageController;
use Packages\Commander\Http\Controllers\CommanderUsersController;
use Packages\Commander\Http\Middleware\CommanderOperatorMiddleware;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [CommanderAuthController::class, 'login'])->middleware('throttle:login');

    // auth:sanctum + operador: rechaza tokens de tenant reutilizados en el panel.
    Route::middleware(['auth:sanctum', CommanderOperatorMiddleware::class])->group(function () {
        Route::get('auth/me', [CommanderAuthController::class, 'me']);
        Route::post('auth/logout', [CommanderAuthController::class, 'logout']);

        // Platform registry
        Route::apiResource('users', CommanderUsersController::class);
        Route::apiResource('clients', CommanderClientsController::class);
        Route::apiResource('plans', CommanderPlansController::class);

        // Client-first provisioning: crea cliente + suscripcion + instancia (core_packages) en un paso
        Route::post('provisioning', [CommanderProvisioningController::class, 'store']);

        // Acciones de estado del operador: in-process directo sobre core_packages (sin HMAC/HTTP).
        Route::post('deployments/{id}/suspend', [CommanderDeploymentsController::class, 'suspend']);
        Route::post('deployments/{id}/grace', [CommanderDeploymentsController::class, 'grace']);
        Route::post('deployments/{id}/resume', [CommanderDeploymentsController::class, 'resume']);
        // Uso y logs reales del package: query directo a core_usage_rollups / core_request_logs.
        Route::get('deployments/{id}/usage', [CommanderDeploymentsController::class, 'usage']);
        Route::get('deployments/{id}/logs', [CommanderDeploymentsController::class, 'logs']);
        Route::apiResource('deployments', CommanderDeploymentsController::class);
        Route::apiResource('subscriptions', CommanderSubscriptionsController::class);
        Route::apiResource('invoices', CommanderInvoicesController::class);
        Route::apiResource('quotas', CommanderQuotasController::class)->only(['index', 'show', 'update']);
        Route::apiResource('activity', CommanderActivityController::class)->only(['index', 'show']);

        // Platform usage (aggregate of the local snapshot cache) for the Dashboard/Usage charts
        Route::get('usage/platform', [CommanderUsageController::class, 'platform']);
    });
});
