<?php

use Illuminate\Support\Facades\Route;
use Packages\Core\Http\Controllers\CoreControlController;
use Packages\Core\Http\Controllers\CoreHealthController;
use Packages\Core\Http\Middleware\CoreControlAuthMiddleware;

Route::middleware(['api'])->group(function () {
    Route::get('/', [CoreHealthController::class, 'index']);
});

// Gestion remota (Commander -> deploy). Sin CORE_CONTROL_SECRET la superficie no existe.
// Nota: este gate usa el secreto de ENTORNO; la verificacion (CoreControlAuthMiddleware) prefiere el
// secreto rotado en BD. CORE_CONTROL_SECRET debe seguir definido (no vaciarlo) tras una rotacion, o las
// rutas /control no se registran aunque haya un secreto rotado valido en core_package_state.
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
