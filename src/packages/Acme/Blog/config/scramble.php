<?php

return [
    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'Blog API.',
    ],
    'ui' => [
        'title' => 'Blog documentation',
    ],
    'rapidoc' => [
        'base' => [
            'fetch_credentials' => 'include',
        ],
    ],
];
