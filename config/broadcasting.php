<?php

return [
    'default' => env('BROADCAST_DRIVER', 'pusher'),

    'connections' => [
        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY', '28fa324491dc6542b0fb'),
            'secret' => env('PUSHER_APP_SECRET', '5bcbce71082f70a31e3d'),
            'app_id' => env('PUSHER_APP_ID', '1997277'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER', 'ap2'),
                'useTLS' => true,
                'encrypted' => true,
            ],
            'client_options' => [
                // Optional: Add any specific Guzzle client options here
                // 'verify' => env('APP_ENV') === 'production' ? true : false,
            ],
        ],

        // Other connections (keep as fallback)
        'log' => [
            'driver' => 'log',
        ],
        'null' => [
            'driver' => 'null',
        ],
    ],
];
