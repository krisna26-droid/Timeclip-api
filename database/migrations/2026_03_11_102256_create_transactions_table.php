<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique(); // Order ID unik untuk Midtrans
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('tier_plan'); // starter, pro, business
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('pending'); // pending, settlement, failed, expired
            $table->string('snap_token')->nullable(); // Token untuk pop-up pembayaran
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
