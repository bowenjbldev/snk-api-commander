<?php

return [
    'defaults' => [
        'guard' => 'sanctum',
        'passwords' => 'users',
    ],
    'guards' => [
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', Packages\Core\Models\CoreUserModel::class),
        ],
    ],
];
