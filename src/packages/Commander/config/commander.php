<?php

return [
    // Operator defaults (mirrors the SPA Settings page).
    'operator' => [
        'default_grace_hours' => (int) env('COMMANDER_DEFAULT_GRACE_HOURS', 72),
    ],

    // Outbound /control client (Commander → each deploy). Per-deploy secret lives on the row.
    'control' => [
        'timeout' => (int) env('COMMANDER_CONTROL_TIMEOUT', 5),
        'connect_timeout' => (int) env('COMMANDER_CONTROL_CONNECT_TIMEOUT', 3),
        // TLS verification of the deploy cert; set false only for self-signed dev endpoints.
        'verify' => (bool) env('COMMANDER_CONTROL_VERIFY', true),
    ],
];
