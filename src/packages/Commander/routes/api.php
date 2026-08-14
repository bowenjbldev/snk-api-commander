<?php

use Illuminate\Support\Facades\Route;
use Packages\Commander\Http\Controllers\CommanderAccountsController;
use Packages\Commander\Http\Controllers\CommanderActivityController;
use Packages\Commander\Http\Controllers\CommanderAuthController;
use Packages\Commander\Http\Controllers\CommanderClientsController;
use Packages\Commander\Http\Controllers\CommanderInvoicesController;
use Packages\Commander\Http\Controllers\CommanderPackagesController;
use Packages\Commander\Http\Controllers\CommanderPlansController;
use Packages\Commander\Http\Controllers\CommanderProvisioningController;
use Packages\Commander\Http\Controllers\CommanderQuotasController;
use Packages\Commander\Http\Controllers\CommanderRequestLogsController;
use Packages\Commander\Http\Controllers\CommanderSettingsController;
use Packages\Commander\Http\Controllers\CommanderSubscriptionsController;
use Packages\Commander\Http\Controllers\CommanderUsageController;
use Packages\Commander\Http\Controllers\CommanderUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [CommanderAuthController::class, 'login'])->middleware('throttle:login');

    // Commander autentica sobre su propia BD. Un token tenant vive en SU BD, así que
    // auth:sanctum no lo encuentra aquí y devuelve 401 (aislamiento por BD).
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [CommanderAuthController::class, 'me']);
        Route::post('auth/logout', [CommanderAuthController::class, 'logout']);

        // Platform registry
        Route::apiResource('users', CommanderUsersController::class);
        Route::apiResource('accounts', CommanderAccountsController::class);
        Route::apiResource('clients', CommanderClientsController::class);
        Route::apiResource('plans', CommanderPlansController::class);

        // Client-first provisioning: crea cliente + suscripcion + instancia (commander_packages) en un paso
        Route::post('provisioning', [CommanderProvisioningController::class, 'store']);

        Route::apiResource('subscriptions', CommanderSubscriptionsController::class);
        Route::apiResource('invoices', CommanderInvoicesController::class);
        Route::apiResource('quotas', CommanderQuotasController::class)->only(['index', 'show', 'update']);
        Route::apiResource('activity', CommanderActivityController::class)->only(['index', 'show']);

        // Platform usage (aggregate of the local snapshot cache) for the Dashboard/Usage charts
        Route::get('usage/platform', [CommanderUsageController::class, 'platform']);

        // Platform-wide request logs (all packages), with optional deployment/method/status filters.
        Route::get('logs', [CommanderRequestLogsController::class, 'index']);

        // Platform-wide recent git commits (all packages) for the Dashboard "Recent commits" card.
        Route::get('commits', [CommanderPackagesController::class, 'commits']);

        // Platform-wide git code frequency (daily additions/deletions across all packages).
        Route::get('code-frequency', [CommanderPackagesController::class, 'codeFrequency']);

        // Package registry + package provisionado 1:1. index sincroniza desde disco + enriquece con planes.
        Route::get('packages', [CommanderPackagesController::class, 'index']);
        Route::post('packages/{package}/suspend', [CommanderPackagesController::class, 'suspend'])->whereNumber('package');
        Route::post('packages/{package}/grace', [CommanderPackagesController::class, 'grace'])->whereNumber('package');
        Route::post('packages/{package}/resume', [CommanderPackagesController::class, 'resume'])->whereNumber('package');
        Route::post('packages/{package}/change-plan', [CommanderPackagesController::class, 'changePlan'])->whereNumber('package');
        Route::get('packages/{package}/usage', [CommanderPackagesController::class, 'usage'])->whereNumber('package');
        Route::get('packages/{package}/logs', [CommanderPackagesController::class, 'logs'])->whereNumber('package');
        Route::get('packages/{package}/insights', [CommanderPackagesController::class, 'insights'])->whereNumber('package');
        Route::get('packages/{package}', [CommanderPackagesController::class, 'show'])->whereNumber('package');
        Route::patch('packages/{package}', [CommanderPackagesController::class, 'update'])->whereNumber('package');
        Route::delete('packages/{package}', [CommanderPackagesController::class, 'destroy'])->whereNumber('package');

        // Operator profile + platform preferences (singleton). default_grace_hours feeds the real grace.
        Route::get('settings', [CommanderSettingsController::class, 'show'])->middleware('can:show settings');
        Route::put('settings', [CommanderSettingsController::class, 'update'])->middleware('can:update settings');
    });
});
