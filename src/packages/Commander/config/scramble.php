<?php

return [
    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'Commander API.',
    ],
    'ui' => [
        'title' => 'Commander documentation',
    ],
    'rapidoc' => [
        'base' => [
            'fetch_credentials' => 'include',
        ],
    ],
];
