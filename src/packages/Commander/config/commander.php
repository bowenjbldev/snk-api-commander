<?php

return [
    // Base domain tenants are provisioned under (e.g. shop.<base_domain>). Used to compute the
    // planned base_url of a 'provisioning' deployment before the host fills the real one.
    'base_domain' => env('COMMANDER_BASE_DOMAIN', 'snake.test'),

    // Operator defaults (mirrors the SPA Settings page).
    'operator' => [
        'default_grace_hours' => (int) env('COMMANDER_DEFAULT_GRACE_HOURS', 72),
    ],
];
