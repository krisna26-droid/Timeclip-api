<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transcription extends Model
{
    protected $fillable = [
        'video_id',
        'full_text',
        'json_data'
    ];

    /**
     * Cast json_data agar otomatis menjadi array saat diakses.
     */
    protected $casts = [
        'json_data' => 'array'
    ];

    /**
     * Relasi ke video induk.
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}