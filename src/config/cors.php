<?php

/**
 * CORS for the API (this is the key `cors` that Laravel's HandleCors reads). In dev the SPA uses a
 * same-origin Vite proxy so CORS isn't exercised; this is for a production build where the SPA is
 * served from a different origin and calls the API directly. Origins come from CORS_ALLOWED_ORIGINS
 * (comma-separated; the SPA dev port is allowed by default). Bearer tokens (not cookies) → no creds.
 *
 * Note: Core also ships packages/Core/config/cors.php, which the package loader detects as the
 * global `cors` key (because this app file exists) and merges ON TOP of this one via
 * array_replace_recursive — so for any key Core defines, Core's value wins. There is no `core.cors`
 * key (config('core.cors') is null). Keep the two files in sync.
 */

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5174'))
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
