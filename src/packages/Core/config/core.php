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
    | Base Domain
    |--------------------------------------------------------------------------
    |
    | Dominio raiz del despliegue centralizado. En modo centralizado (API_PACKAGE
    | vacio) cada package se sirve en {subdominio}.{base_domain} y el package se
    | resuelve por host. Debe coincidir con BASE_DOMAIN de la infra (nginx/DNS).
    |
    */

    'base_domain' => env('BASE_DOMAIN', 'snake.test'),

    /*
    |--------------------------------------------------------------------------
    | Control Plane (tenant self-management)
    |--------------------------------------------------------------------------
    |
    | Whether this deploy runs the tenant control plane: metering, kill-switch,
    | quotas and the /control surface. Customer deploys leave it on; the operator
    | panel (API_PACKAGE=Commander) turns it off — it manages tenants, it is not one.
    |
    */

    'control_plane' => (bool) env('CORE_CONTROL_PLANE', true),

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
