<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            // Menambahkan kolom export_path setelah clip_path
            $table->string('export_path')->nullable()->after('clip_path');
        });
    }

    public function down(): void
    {
        Schema::table('clips', function (Blueprint $table) {
            $table->dropColumn('export_path');
        });
    }
};
