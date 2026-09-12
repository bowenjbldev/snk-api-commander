<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base Domain
    |--------------------------------------------------------------------------
    |
    | Dominio del control plane y dominio de plataforma para las APIs tenant.
    | El host identifica al cliente; /api/v1/{producto} selecciona el package.
    | En local: commander.snakepc.test y {cliente}.commander.test/api/v1/kilkana.
    | TENANT_BASE_DOMAIN usa BASE_DOMAIN como fallback para los despliegues
    | que sirven todo desde un único dominio.
    |
    */

    'base_domain' => env('BASE_DOMAIN', 'snakepc.test'),
    'tenant_base_domain' => env('TENANT_BASE_DOMAIN', env('BASE_DOMAIN', 'snakepc.test')),

    /*
    |--------------------------------------------------------------------------
    | Commander (el control plane del tenant)
    |--------------------------------------------------------------------------
    |
    | Si este deploy ejecuta el control plane del commander: metering, kill-switch
    | y cuotas (encendido por defecto). Se apaga con CORE_COMMANDER=false en los
    | deploys que no deban medir ni aplicar cuotas.
    |
    */

    'commander' => (bool) env('CORE_COMMANDER', true),

    'auth' => [
        'token_expiration_minutes' => (int) env('CORE_TOKEN_EXPIRATION_MINUTES', 1440),
        'admin_email' => env('CORE_ADMIN_EMAIL', 'admin@snake.com'),
        'admin_password' => env('CORE_ADMIN_PASSWORD'),
    ],

    'rate_limits' => [
        'api_per_minute' => (int) env('CORE_API_RATE_LIMIT', 240),
        'login_account_failures_per_minute' => (int) env('CORE_LOGIN_ACCOUNT_RATE_LIMIT', 5),
        // CORE_LOGIN_RATE_LIMIT conserva compatibilidad con despliegues anteriores.
        'login_ip_failures_per_minute' => (int) env('CORE_LOGIN_IP_RATE_LIMIT', env('CORE_LOGIN_RATE_LIMIT', 10)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Package State (kill-switch)
    |--------------------------------------------------------------------------
    |
    | Estado local del deploy (active|grace|suspended) leído por el middleware
    | con caché. Las rutas exentas siguen respondiendo aunque esté suspendido.
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
        // Filas que un worker acumula antes de escribirlas, y segundos máximos de espera. Con 1 se
        // escribe al momento (FPM, consola, pruebas); en Octane el compose pone 20.
        'batch' => (int) env('CORE_METERING_BATCH', 1),
        'batch_seconds' => (float) env('CORE_METERING_BATCH_SECONDS', 5),
        'skip_paths' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quotas
    |--------------------------------------------------------------------------
    |
    | Límites null = sin límite. La acción al superarlo se define por fila en BD.
    |
    */

    'quotas' => [
        'grace_hours' => (int) env('CORE_QUOTA_GRACE_HOURS', 72),
        'defaults' => [
            'disk_db_mb' => env('CORE_QUOTA_DISK_DB_MB'),
            'disk_storage_mb' => env('CORE_QUOTA_DISK_STORAGE_MB'),
            'requests_day' => env('CORE_QUOTA_REQUESTS_DAY'),
            'users_total' => env('CORE_QUOTA_USERS_TOTAL'),
        ],
    ],

];
