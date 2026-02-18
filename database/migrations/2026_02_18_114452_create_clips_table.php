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
        Schema::create('clips', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->onDelete('cascade'); //[cite: 221]
            $table->string('title'); //[cite: 222]
            $table->integer('start_time'); // dalam detik [cite: 223]
            $table->integer('end_time'); // dalam detik [cite: 224]
            $table->integer('viral_score')->default(0); //[cite: 225]
            $table->string('clip_path')->nullable(); //[cite: 226]
            $table->enum('status', ['rendering', 'ready', 'failed'])->default('rendering'); //[cite: 227]
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clips');
    }
};
