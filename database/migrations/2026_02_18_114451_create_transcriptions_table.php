<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcriptions', function (Blueprint $table) {
            $table->id();
            // Hubungkan ke tabel videos [cite: 213]
            $table->foreignId('video_id')->constrained()->onDelete('cascade'); 
            // Teks utuh hasil transkripsi [cite: 214]
            $table->longText('full_text'); 
            // Data JSON untuk word-level timestamps [cite: 215]
            $table->json('json_data'); 
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcriptions');
    }
};