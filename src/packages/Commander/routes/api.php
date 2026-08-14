<?php

use Illuminate\Support\Facades\Route;
use Packages\Commander\Http\Controllers\CommanderAccountsController;
use Packages\Commander\Http\Controllers\CommanderActivityController;
use Packages\Commander\Http\Controllers\CommanderApplicationsController;
use Packages\Commander\Http\Controllers\CommanderAuthController;
use Packages\Commander\Http\Controllers\CommanderClientsController;
use Packages\Commander\Http\Controllers\CommanderInstancesController;
use Packages\Commander\Http\Controllers\CommanderInvoicesController;
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
        Route::apiResource('applications', CommanderApplicationsController::class)->only(['index', 'show', 'update']);
        Route::apiResource('plans', CommanderPlansController::class);

        // Alta atomica de cuenta/cliente/contrato/instancia; el worker hace el DDL.
        Route::post('provisioning', [CommanderProvisioningController::class, 'store']);

        Route::apiResource('subscriptions', CommanderSubscriptionsController::class);
        Route::apiResource('invoices', CommanderInvoicesController::class);
        Route::apiResource('quotas', CommanderQuotasController::class)->only(['index', 'show', 'update']);
        Route::apiResource('activity', CommanderActivityController::class)->only(['index', 'show']);

        // Platform usage (aggregate of the local snapshot cache) for the Dashboard/Usage charts
        Route::get('usage/platform', [CommanderUsageController::class, 'platform']);

        // Platform-wide request logs (all packages), with optional deployment/method/status filters.
        Route::get('logs', [CommanderRequestLogsController::class, 'index']);

        Route::get('commits', [CommanderApplicationsController::class, 'commits']);
        Route::get('code-frequency', [CommanderApplicationsController::class, 'codeFrequency']);
        Route::get('applications/{application}/insights', [CommanderApplicationsController::class, 'insights'])->whereNumber('application');

        Route::get('instances', [CommanderInstancesController::class, 'index']);
        Route::get('instances/{instance}', [CommanderInstancesController::class, 'show'])->whereNumber('instance');
        Route::post('instances/{instance}/suspend', [CommanderInstancesController::class, 'suspend'])->whereNumber('instance');
        Route::post('instances/{instance}/grace', [CommanderInstancesController::class, 'grace'])->whereNumber('instance');
        Route::post('instances/{instance}/resume', [CommanderInstancesController::class, 'resume'])->whereNumber('instance');
        Route::post('instances/{instance}/change-plan', [CommanderInstancesController::class, 'changePlan'])->whereNumber('instance');
        Route::post('instances/{instance}/retry', [CommanderInstancesController::class, 'retry'])->whereNumber('instance');
        Route::get('instances/{instance}/usage', [CommanderInstancesController::class, 'usage'])->whereNumber('instance');
        Route::get('instances/{instance}/logs', [CommanderInstancesController::class, 'logs'])->whereNumber('instance');
        Route::delete('instances/{instance}', [CommanderInstancesController::class, 'destroy'])->whereNumber('instance');

        // Operator profile + platform preferences (singleton). default_grace_hours feeds the real grace.
        Route::get('settings', [CommanderSettingsController::class, 'show'])->middleware('can:show settings');
        Route::put('settings', [CommanderSettingsController::class, 'update'])->middleware('can:update settings');
    });
});
