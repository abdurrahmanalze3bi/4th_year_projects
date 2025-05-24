<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */
    'openroute' => [
        'api_key' => env('OPENROUTE_API_KEY'),
        'cache_ttl' => env('OPENROUTE_CACHE_TTL', 86400),
        'ssl_verify' => env('OPENROUTE_SSL_VERIFY', true),
        'timeout' => env('OPENROUTE_TIMEOUT', 30),
    ],
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
// config/services.php
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'), // Reference .env variable name
        'client_secret' => env('GOOGLE_CLIENT_SECRET'), // Reference .env variable name
        'redirect' => env('GOOGLE_REDIRECT_URL'), // Reference .env variable name
    ],
    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY'),
        'sender_id' => env('FCM_SENDER_ID'),
    ],
];
