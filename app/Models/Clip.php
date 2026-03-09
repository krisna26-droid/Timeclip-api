<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Clip extends Model
{
    protected $fillable = [
        'video_id',
        'title',
        'start_time',
        'end_time',
        'viral_score',
        'clip_path',
        'thumbnail_path',
        'status'
    ];

    protected $appends = ['duration_formatted'];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function subtitle(): HasOne
    {
        return $this->hasOne(ClipSubtitle::class);
    }

    public function getDurationFormattedAttribute()
    {
        $seconds = $this->end_time - $this->start_time;
        return gmdate("i:s", $seconds);
    }
}
