<?php

return [
    'api_path' => 'api/v1',
    'docs' => [
        'ui_path' => 'api/v1/docs',
        'json_path' => 'api/v1/docs.json',
    ],
    'ui' => [
        'theme' => 'dark',
    ],
    // RapiDoc HTML attributes (kebab-case)
    'rapidoc' => [
        'spec-url' => 'docs.json',
        'render-style' => 'read',
        'show-header' => 'false',
        'allow-spec-url-load' => 'false',
        'allow-spec-file-load' => 'false',
        'allow-server-selection' => 'false',
        'show-method-in-nav-bar' => 'as-colored-block',
        'use-path-in-nav-bar' => 'true',
        'sort-tags' => false,
        'sort-schemas' => false,
        'sort-endpoints-by' => 'path',
    ],
];
