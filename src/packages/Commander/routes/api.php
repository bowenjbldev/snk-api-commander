<?php

use Illuminate\Support\Facades\Route;
use Packages\Commander\Http\Controllers\CommanderActivityController;
use Packages\Commander\Http\Controllers\CommanderAuthController;
use Packages\Commander\Http\Controllers\CommanderClientsController;
use Packages\Commander\Http\Controllers\CommanderDeploymentsController;
use Packages\Commander\Http\Controllers\CommanderInvoicesController;
use Packages\Commander\Http\Controllers\CommanderPlansController;
use Packages\Commander\Http\Controllers\CommanderPackagesController;
use Packages\Commander\Http\Controllers\CommanderProvisioningController;
use Packages\Commander\Http\Controllers\CommanderQuotasController;
use Packages\Commander\Http\Controllers\CommanderRequestLogsController;
use Packages\Commander\Http\Controllers\CommanderSettingsController;
use Packages\Commander\Http\Controllers\CommanderSubscriptionsController;
use Packages\Commander\Http\Controllers\CommanderUsageController;
use Packages\Commander\Http\Controllers\CommanderUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [CommanderAuthController::class, 'login'])->middleware('throttle:login');

    // El panel corre sobre la BD commander (operador). Un token de tenant vive en SU BD, no en la
    // commander -> auth:sanctum no lo encuentra aqui -> 401 automatico (aislamiento por BD).
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [CommanderAuthController::class, 'me']);
        Route::post('auth/logout', [CommanderAuthController::class, 'logout']);

        // Platform registry
        Route::apiResource('users', CommanderUsersController::class);
        Route::apiResource('clients', CommanderClientsController::class);
        Route::apiResource('plans', CommanderPlansController::class);

        // Client-first provisioning: crea cliente + suscripcion + instancia (commander_packages) en un paso
        Route::post('provisioning', [CommanderProvisioningController::class, 'store']);

        // Acciones de estado del operador: in-process directo sobre commander_packages.
        Route::post('deployments/{id}/suspend', [CommanderDeploymentsController::class, 'suspend']);
        Route::post('deployments/{id}/grace', [CommanderDeploymentsController::class, 'grace']);
        Route::post('deployments/{id}/resume', [CommanderDeploymentsController::class, 'resume']);
        // Uso y logs reales del package: query directo a commander_usage_rollups / commander_request_logs.
        Route::get('deployments/{id}/usage', [CommanderDeploymentsController::class, 'usage']);
        Route::get('deployments/{id}/logs', [CommanderDeploymentsController::class, 'logs']);
        Route::apiResource('deployments', CommanderDeploymentsController::class);
        Route::apiResource('subscriptions', CommanderSubscriptionsController::class);
        Route::apiResource('invoices', CommanderInvoicesController::class);
        Route::apiResource('quotas', CommanderQuotasController::class)->only(['index', 'show', 'update']);
        Route::apiResource('activity', CommanderActivityController::class)->only(['index', 'show']);

        // Platform usage (aggregate of the local snapshot cache) for the Dashboard/Usage charts
        Route::get('usage/platform', [CommanderUsageController::class, 'platform']);

        // Platform-wide request logs (all packages), with optional deployment/method/status filters.
        Route::get('logs', [CommanderRequestLogsController::class, 'index']);

        // Package registry (tipos): hub package-centrico. index sincroniza desde disco + enriquece
        // con on_disk / conteo de deployments y planes. Metadatos editables (label/description/status).
        Route::get('packages', [CommanderPackagesController::class, 'index']);
        Route::get('packages/{package}', [CommanderPackagesController::class, 'show']);
        Route::patch('packages/{package}', [CommanderPackagesController::class, 'update']);

        // Operator profile + platform preferences (singleton). default_grace_hours feeds the real grace.
        Route::get('settings', [CommanderSettingsController::class, 'show']);
        Route::put('settings', [CommanderSettingsController::class, 'update']);
    });
});
