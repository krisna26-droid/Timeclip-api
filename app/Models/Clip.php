<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Clip extends Model
{
    protected $fillable = [
        'video_id',
        'title',
        'start_time',
        'end_time',
        'viral_score',
        'clip_path',
        'status'
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class); //[cite: 221, 229]
    }

    protected $appends = ['duration_formatted'];

    public function getDurationFormattedAttribute()
    {
        $seconds = $this->end_time - $this->start_time;
        return gmdate("i:s", $seconds); // Mengubah ke format MM:SS
    }
}
