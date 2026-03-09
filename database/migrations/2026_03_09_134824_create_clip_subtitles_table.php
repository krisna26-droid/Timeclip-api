<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clip_subtitles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clip_id')->constrained()->onDelete('cascade');
            $table->longText('full_text');
            $table->json('words');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clip_subtitles');
    }
};