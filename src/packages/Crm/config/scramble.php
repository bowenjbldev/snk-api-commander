<?php

return [
    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'Crm API.',
    ],
    'ui' => [
        'title' => 'Crm documentation',
    ],
    'rapidoc' => [
        'base' => [
            'fetch_credentials' => 'include',
        ],
    ],
];
