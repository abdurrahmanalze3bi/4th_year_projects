<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => [
        'web/*',
        'api/*',
        'admin/*',
        'sanctum/csrf-cookie',
        'session-debug', // Add your debug route
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',  // Flutter web development server
        'http://127.0.0.1:3000',
        'http://localhost:8080',  // Alternative Flutter web port
        'http://127.0.0.1:8080',
        // Add your production domain here when deploying
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
        'Cache-Control',
        'Pragma',
    ],

    'exposed_headers' => [
        'Set-Cookie',
        'X-CSRF-TOKEN',
    ],

    'max_age' => 0,

    'supports_credentials' => true, // This is CRUCIAL for session cookies
];
