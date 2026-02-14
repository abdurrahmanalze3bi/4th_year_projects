<?php

return [
    /*
    |--------------------------------------------------------------------------
    | JWT Authentication Secret
    |--------------------------------------------------------------------------
    |
    | Secret key used to sign JWT tokens. This should be a strong random string.
    | Generate using: php artisan jwt:secret
    |
    */
    'secret' => env('JWT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | JWT time to live (Access Token)
    |--------------------------------------------------------------------------
    |
    | Specify the length of time (in minutes) that the access token will be
    | valid for. Default is 15 minutes for security.
    |
    | Recommended values:
    | - Development: 60 (1 hour)
    | - Production: 15 (15 minutes)
    |
    */
    'ttl' => (int) env('JWT_TTL', 15),

    /*
    |--------------------------------------------------------------------------
    | Refresh time to live (Refresh Token)
    |--------------------------------------------------------------------------
    |
    | Specify the length of time (in minutes) that the refresh token will be
    | valid for. Default is 7 days.
    |
    | Recommended values:
    | - Mobile apps: 10080 (7 days) to 43200 (30 days)
    | - Web apps: 10080 (7 days)
    |
    */
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 10080),

    /*
    |--------------------------------------------------------------------------
    | JWT hashing algorithm
    |--------------------------------------------------------------------------
    |
    | Specify the hashing algorithm that will be used to sign the token.
    |
    | Supported: HS256, HS384, HS512
    |
    */
    'algo' => env('JWT_ALGO', 'HS256'),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Optional prefix for tokens (e.g., for GitHub secret scanning)
    |
    */
    'token_prefix' => env('JWT_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Leeway
    |--------------------------------------------------------------------------
    |
    | This property gives the jwt timestamp claims some "leeway".
    | Meaning that if you have any unavoidable slight clock skew on
    | any of your servers then this will afford you some level of cushioning.
    |
    | This applies to the claims `iat`, `nbf` and `exp`.
    |
    | Specify in seconds - only if you know you need it.
    |
    */
    'leeway' => (int) env('JWT_LEEWAY', 0),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Rate limit configuration for token refresh endpoint
    |
    */
    'rate_limit' => [
        'refresh' => [
            'max_attempts' => 5,
            'decay_minutes' => 1
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Cleanup
    |--------------------------------------------------------------------------
    |
    | Automatically cleanup expired tokens older than specified days
    |
    */
    'cleanup' => [
        'enabled' => env('JWT_CLEANUP_ENABLED', true),
        'older_than_days' => env('JWT_CLEANUP_DAYS', 30)
    ]
];
