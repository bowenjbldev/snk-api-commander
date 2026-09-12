<?php

/**
 * CORS para las llamadas directas del navegador a la API. Los orígenes salen de
 * CORS_ALLOWED_ORIGINS, una lista separada por comas. Se autentica con bearer tokens y no con
 * cookies, así que las credenciales siguen desactivadas.
 *
 * Core trae también packages/Core/config/cors.php, que el cargador de packages detecta como la
 * clave global `cors` (porque existe este fichero de la app) y mergea ENCIMA de este con
 * array_replace_recursive: en toda clave que Core defina, gana el valor de Core. No hay clave
 * `core.cors` (config('core.cors') es null). Los dos ficheros se mantienen alineados.
 */
$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
