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
        Schema::create('videos', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); //[cite: 202]
            $table->string('title'); //[cite: 203]
            $table->text('source_url')->nullable(); // link yt/tiktok [cite: 204]
            $table->string('file_path')->nullable(); // lokasi di S3 [cite: 205]
            $table->integer('duration'); //[cite: 206]
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending'); //[cite: 207]
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
