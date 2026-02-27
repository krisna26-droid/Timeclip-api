<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    // Menambahkan 'videos/*' agar route pemrosesan video diizinkan
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'videos/*'],

    'allowed_methods' => ['*'],

    // Menyesuaikan dengan URL Front-End yang ada di .env kamu
    'allowed_origins' => ['http://localhost:5173', 'http://127.0.0.1:5173'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Wajib TRUE agar Sanctum bisa mengirimkan cookie/token autentikasi
    'supports_credentials' => true,
];