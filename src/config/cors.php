<?php

/**
 * CORS for direct browser calls to the API. Origins come from CORS_ALLOWED_ORIGINS as a
 * comma-separated list. Bearer tokens are used instead of cookies, so credentials stay disabled.
 *
 * Note: Core also ships packages/Core/config/cors.php, which the package loader detects as the
 * global `cors` key (because this app file exists) and merges ON TOP of this one via
 * array_replace_recursive — so for any key Core defines, Core's value wins. There is no `core.cors`
 * key (config('core.cors') is null). Keep the two files in sync.
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
