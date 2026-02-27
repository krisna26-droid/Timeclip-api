<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    // 1. Tambahkan 'videos/*' agar route video bisa diakses FE
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'videos/*'],

    'allowed_methods' => ['*'],

    // 2. Meskipun '*' bekerja, lebih baik spesifik ke port Vite agar lebih aman
    'allowed_origins' => ['http://localhost:5173', 'http://127.0.0.1:5173'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // 3. Set true jika temanmu menggunakan Laravel Sanctum untuk login
    'supports_credentials' => true,

];