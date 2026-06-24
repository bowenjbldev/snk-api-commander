<?php

return [
    // Base domain tenants are provisioned under (e.g. shop.<base_domain>). Used to compute the
    // planned base_url of a 'provisioning' deployment before the host fills the real one.
    'base_domain' => env('COMMANDER_BASE_DOMAIN', 'snake.test'),

    // Operator defaults (mirrors the SPA Settings page).
    'operator' => [
        'default_grace_hours' => (int) env('COMMANDER_DEFAULT_GRACE_HOURS', 72),
    ],

    // Git insights: CommanderLocalGitSource lee el .git montado read-only en el contenedor.
    // `git log` solo necesita la base de objetos (git_dir); work_tree fija la base del pathspec.
    'git' => [
        'git_dir' => env('COMMANDER_GIT_DIR', '/var/www/.git'),
        'work_tree' => env('COMMANDER_GIT_WORK_TREE', '/var/www'),
        'binary' => env('COMMANDER_GIT_BIN', 'git'),
    ],
];
