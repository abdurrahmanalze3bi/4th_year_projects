<?php

return [
    'paths' => ['api/*', 'admin/*', 'sanctum/csrf-cookie'], // Add admin/* here

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true, // Change this from false to true
];
