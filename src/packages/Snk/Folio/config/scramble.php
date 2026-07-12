<?php

return [
    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'SnkFolio API.',
    ],
    'ui' => [
        'title' => 'SnkFolio documentation',
    ],
    'rapidoc' => [
        'base' => [
            'fetch_credentials' => 'include',
        ],
    ],
];
