<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Broadcast; // Tambahkan ini

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Ini akan mengubah rute menjadi /api/broadcasting/auth
        // Dan memaksanya pakai pengamanan Sanctum
        Broadcast::routes([
            'prefix' => 'api',
            'middleware' => ['api', 'auth:sanctum']
        ]);

        require base_path('routes/channels.php');
    }
}
