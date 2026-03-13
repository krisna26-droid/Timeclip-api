<?php

return [
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'register',
        'auth/github/*',
        'payment/callback',
        'broadcasting/auth',
        'storage/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Content-Disposition',
        'Content-Length',
        'Content-Range',
        'Accept-Ranges',
    ],

    'max_age' => 86400,

    'supports_credentials' => true,
];
