<?php

return [
    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => '{{class}} API.',
    ],
    'ui' => [
        'title' => '{{class}} documentation',
    ],
    'rapidoc' => [
        'base' => [
            'fetch_credentials' => 'include',
        ],
    ],
];