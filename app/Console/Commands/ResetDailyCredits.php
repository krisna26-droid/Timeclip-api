<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResetDailyCredits extends Command
{
    protected $signature = 'credits:reset';
    protected $description = 'Refill harian kredit user sesuai Tier dan Max Cap';

    public function handle()
    {
        // 1. Refill Tier Free: +2 token, Max Cap 10
        User::where('tier', 'free')
            ->where('remaining_credits', '<', 10)
            ->update(['remaining_credits' => DB::raw('LEAST(remaining_credits + 2, 10)')]);

        // 2. Refill Tier Starter: +10 token, Max Cap 100
        User::where('tier', 'starter')
            ->where('remaining_credits', '<', 100)
            ->update(['remaining_credits' => DB::raw('LEAST(remaining_credits + 10, 100)')]);

        // 3. Refill Tier Pro: +20 token, Max Cap 300
        User::where('tier', 'pro')
            ->where('remaining_credits', '<', 300)
            ->update(['remaining_credits' => DB::raw('LEAST(remaining_credits + 20, 300)')]);

        // Note: Tier Business tidak perlu refill karena unlimited

        $this->info('Refill kredit harian berhasil dijalankan sesuai limit masing-masing tier.');
    }
}