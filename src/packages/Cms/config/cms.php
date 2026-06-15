<?php

return [
    // Operator defaults (mirrors the SPA Settings page).
    'operator' => [
        'default_grace_hours' => (int) env('CMS_DEFAULT_GRACE_HOURS', 72),
    ],

    // Outbound /control client (CMS → each deploy). Per-deploy secret lives on the row.
    'control' => [
        'timeout' => (int) env('CMS_CONTROL_TIMEOUT', 5),
        'connect_timeout' => (int) env('CMS_CONTROL_CONNECT_TIMEOUT', 3),
        // TLS verification of the deploy cert; set false only for self-signed dev endpoints.
        'verify' => (bool) env('CMS_CONTROL_VERIFY', true),
    ],
];
