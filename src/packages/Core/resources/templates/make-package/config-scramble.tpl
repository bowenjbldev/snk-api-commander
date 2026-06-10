<?php

return [
    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => '{{package}} API.',
    ],
    'ui' => [
        'title' => '{{package}} documentation',
    ],
    'rapidoc' => [
        'base' => [
            'fetch_credentials' => 'include',
        ],
    ],
];