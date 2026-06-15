<?php

use Illuminate\Support\Facades\Route;
use Packages\Core\Http\Controllers\CoreControlController;
use Packages\Core\Http\Controllers\CoreHealthController;
use Packages\Core\Http\Middleware\CoreControlAuthMiddleware;

Route::middleware(['api'])->group(function () {
    Route::get('/', [CoreHealthController::class, 'index']);
});

// Gestion remota (CMS -> deploy). Sin CORE_CONTROL_SECRET la superficie no existe.
if ((string) config('core.control.secret', '') !== '') {
    Route::middleware(['api', CoreControlAuthMiddleware::class])
        ->prefix('control')
        ->group(function () {
            Route::get('state', [CoreControlController::class, 'showState']);
            Route::post('state', [CoreControlController::class, 'updateState']);
            Route::get('usage', [CoreControlController::class, 'usage']);
            Route::post('rotate-secret', [CoreControlController::class, 'rotateSecret']);
        });
}
