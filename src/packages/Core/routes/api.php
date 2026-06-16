<?php

use Illuminate\Support\Facades\Route;
use Packages\Core\Http\Controllers\CoreControlController;
use Packages\Core\Http\Controllers\CoreHealthController;
use Packages\Core\Http\Middleware\CoreControlAuthMiddleware;

Route::middleware(['api'])->group(function () {
    Route::get('/', [CoreHealthController::class, 'index']);
});

// Gestion remota (Commander -> deploy). Requiere control plane activo Y un secreto.
// Sin CORE_CONTROL_SECRET la superficie no existe; con CORE_CONTROL_PLANE=false (el Commander) tampoco.
// Nota: el gate del secreto usa el de ENTORNO; la verificacion (CoreControlAuthMiddleware) prefiere el
// secreto rotado en BD. CORE_CONTROL_SECRET debe seguir definido (no vaciarlo) tras una rotacion, o las
// rutas /control no se registran aunque haya un secreto rotado valido en core_package_state.
if (config('core.control_plane', true) && (string) config('core.control.secret', '') !== '') {
    Route::middleware(['api', CoreControlAuthMiddleware::class])
        ->prefix('control')
        ->group(function () {
            Route::get('state', [CoreControlController::class, 'showState']);
            Route::post('state', [CoreControlController::class, 'updateState']);
            Route::get('usage', [CoreControlController::class, 'usage']);
            Route::get('logs', [CoreControlController::class, 'logs']);
            Route::post('rotate-secret', [CoreControlController::class, 'rotateSecret']);
        });
}
