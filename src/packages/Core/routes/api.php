<?php

use Illuminate\Support\Facades\Route;
use Packages\Core\Http\Controllers\CoreHealthController;

Route::middleware(['api'])->group(function () {
    Route::get('/', [CoreHealthController::class, 'index']);
});
