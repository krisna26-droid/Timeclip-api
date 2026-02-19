<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Buat Admin Utama
        User::factory()->create([
            'name' => 'Admin Utama',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'tier' => 'business',
            'remaining_credits' => 9999,
            'last_reset_date' => now(),
        ]);

        // 2. Buat Creator dengan Tier Pro
        User::factory()->create([
            'name' => 'Pro Creator',
            'email' => 'creator@example.com',
            'password' => Hash::make('password123'),
            'role' => 'creator',
            'tier' => 'pro',
            'remaining_credits' => 100,
            'last_reset_date' => now(),
        ]);

        // 3. Buat 10 User Random (Default: Tier Free, Role Creator)
        // Pastikan UserFactory kamu sudah mendukung kolom baru ini atau biarkan default dari migration
        User::factory(10)->create();
    }
}