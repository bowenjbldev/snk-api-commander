<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active Package
    |--------------------------------------------------------------------------
    |
    | The business package served by this deployment. One deployment serves
    | exactly one package (plus Core). Leave empty to boot Core only.
    |
    */

    'package' => env('API_PACKAGE'),

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
        'cache_key' => 'core:package_state',
        'exempt_paths' => ['api/v1', 'api/v1/control/*'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote Control (Commander -> deploy)
    |--------------------------------------------------------------------------
    |
    | Endpoints /api/v1/control/* firmados con HMAC-SHA256. Sin secreto, las
    | rutas no se registran (superficie cerrada por defecto).
    |
    */

    'control' => [
        'secret' => env('CORE_CONTROL_SECRET'),
        'clock_skew_seconds' => (int) env('CORE_CONTROL_CLOCK_SKEW', 300),
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
