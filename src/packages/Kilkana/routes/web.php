<?php

use Illuminate\Support\Facades\Route;
use Packages\Kilkana\Http\Controllers\SnkKilkanaMediaVariantController;
use Packages\Kilkana\Services\SnkKilkanaImageService;

/*
 * Lo que el periódico sirve fuera de la API: las derivadas de imagen que nginx no tiene todavía.
 * La ruta es la misma que el fichero tendrá en disco, y por eso solo se llega aquí la primera
 * vez; las restricciones son la lista blanca de la fábrica, y lo que no encaja no casa la ruta.
 */
Route::get('storage/media/{base}/v/{id}/{key}/{ratio}-{width}.{format}', [SnkKilkanaMediaVariantController::class, 'show'])
    ->where([
        'base' => '[a-z0-9_]+',
        'id' => '[0-9]+',
        'key' => '[0-9a-f]{8}',
        'ratio' => implode('|', array_keys(SnkKilkanaImageService::RATIOS)),
        'width' => '[0-9]{3,4}',
        'format' => implode('|', SnkKilkanaImageService::FORMATS),
    ]);
