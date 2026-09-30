<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'health', 'health/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://localhost:3001')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-ID', 'X-Correlation-ID'],

    'max_age' => 0,

    'supports_credentials' => false,

];
