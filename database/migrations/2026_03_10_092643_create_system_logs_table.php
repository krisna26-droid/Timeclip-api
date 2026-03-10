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
        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->string('service'); // 'GEMINI', 'FFMPEG', 'YT-DLP', 'SYSTEM'
            $table->string('level');   // 'INFO', 'ERROR', 'WARNING'
            $table->string('category'); // 'USAGE', 'RENDER', 'AUTH'
            $table->text('message');
            $table->json('payload')->nullable(); 
            
            // Tambahkan baris ini di file lama kamu
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_logs');
    }
};