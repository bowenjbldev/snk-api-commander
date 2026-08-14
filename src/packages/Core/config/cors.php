<?php

return [
    // Solo las rutas de la API necesitan CORS (health, docs y negocio cuelgan de api/v1). NO usar
    // ['*']: este fichero se mergea ENCIMA del global config('cors') y un '*' abriria CORS en TODAS
    // las rutas del deploy. Mantener alineado con src/config/cors.php.
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map(
        static fn(string $origin): string => trim($origin),
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            '',
        )),
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => filter_var(
        env('CORS_SUPPORTS_CREDENTIALS', false),
        FILTER_VALIDATE_BOOL
    ),
];
