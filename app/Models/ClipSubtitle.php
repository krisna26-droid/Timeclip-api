<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClipSubtitle extends Model
{
    protected $fillable = [
        'clip_id',
        'full_text',
        'words',
    ];

    protected $casts = [
        'words' => 'array',
    ];

    public function clip(): BelongsTo
    {
        return $this->belongsTo(Clip::class);
    }
}
