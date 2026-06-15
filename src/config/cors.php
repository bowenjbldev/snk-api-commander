<?php

/**
 * CORS for the API (this is the key `cors` that Laravel's HandleCors reads). In dev the SPA uses a
 * same-origin Vite proxy so CORS isn't exercised; this is for a production build where the SPA is
 * served from a different origin and calls the API directly. Origins come from CORS_ALLOWED_ORIGINS
 * (comma-separated; the SPA dev port is allowed by default). Bearer tokens (not cookies) → no creds.
 *
 * Note: Core ships its own cors.php but it loads under the namespaced `core.cors` key (package
 * config convention) and is NOT what HandleCors consumes — this app-level file is.
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
