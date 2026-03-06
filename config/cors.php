<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'register',
        'storage/*',    // Agar file video/thumbnail/klip bisa diakses FE
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:3000',    // Antisipasi kalau FE pakai port lain
        'http://127.0.0.1:3000',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Content-Disposition',  // Wajib agar download file bisa terbaca nama filenya
    ],

    'max_age' => 86400, // Cache preflight 1 hari agar tidak lambat

    // Wajib TRUE agar Sanctum bisa kirim cookie/token autentikasi
    'supports_credentials' => true,
];
