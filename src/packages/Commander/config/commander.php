<?php

return [
    // Git insights: CommanderLocalGitSource lee el .git montado read-only en el contenedor.
    // `git log` solo necesita la base de objetos (git_dir); work_tree fija la base del pathspec.
    'git' => [
        'git_dir' => env('COMMANDER_GIT_DIR', '/var/www/.git'),
        'work_tree' => env('COMMANDER_GIT_WORK_TREE', '/var/www'),
        'binary' => env('COMMANDER_GIT_BIN', 'git'),
    ],
];
