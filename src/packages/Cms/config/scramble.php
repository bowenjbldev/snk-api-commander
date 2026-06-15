<?php

return [
    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'Cms API.',
    ],
    'ui' => [
        'title' => 'Cms documentation',
    ],
    'rapidoc' => [
        'base' => [
            'fetch_credentials' => 'include',
        ],
    ],
];
