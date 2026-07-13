<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base Domain
    |--------------------------------------------------------------------------
    |
    | Dominio raiz del despliegue centralizado: cada package se sirve en
    | {subdominio}.{base_domain} y el package se resuelve por host en cada request.
    | Debe coincidir con BASE_DOMAIN de la infra (nginx/DNS).
    |
    */

    'base_domain' => env('BASE_DOMAIN', 'snake.test'),

    /*
    |--------------------------------------------------------------------------
    | Commander (el control plane del tenant)
    |--------------------------------------------------------------------------
    |
    | Whether this deploy runs the commander control plane: metering, kill-switch
    | y quotas (default on). Apagable con CORE_COMMANDER=false en deploys que no
    | deban medir ni aplicar cuotas.
    |
    */

    'commander' => (bool) env('CORE_COMMANDER', true),

    'auth' => [
        'token_expiration_minutes' => (int) env('CORE_TOKEN_EXPIRATION_MINUTES', 1440),
        'admin_email' => env('CORE_ADMIN_EMAIL', 'admin@snake.com'),
        'admin_password' => env('CORE_ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Package State (kill-switch)
    |--------------------------------------------------------------------------
    |
    | Estado local del deploy (active|grace|suspended) leido por el middleware
    | con cache. Las rutas exentas siguen respondiendo aunque este suspendido.
    |
    */

    'state' => [
        'cache_ttl' => (int) env('CORE_STATE_CACHE_TTL', 30),
        'exempt_paths' => ['api/v1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Metering
    |--------------------------------------------------------------------------
    */

    'metering' => [
        'enabled' => (bool) env('CORE_METERING_ENABLED', true),
        'sample_rate' => (float) env('CORE_METERING_SAMPLE_RATE', 1.0),
        'retention_days' => (int) env('CORE_METERING_RETENTION_DAYS', 30),
        'skip_paths' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quotas
    |--------------------------------------------------------------------------
    |
    | Limites null = sin limite. on_exceed se define por fila en BD.
    |
    */

    'quotas' => [
        'grace_hours' => (int) env('CORE_QUOTA_GRACE_HOURS', 72),
        'defaults' => [
            'disk_db_mb'      => env('CORE_QUOTA_DISK_DB_MB'),
            'disk_storage_mb' => env('CORE_QUOTA_DISK_STORAGE_MB'),
            'requests_day'    => env('CORE_QUOTA_REQUESTS_DAY'),
            'users_total'     => env('CORE_QUOTA_USERS_TOTAL'),
        ],
    ],

];
