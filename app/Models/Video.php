<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Video extends Model
{
    protected $fillable = [
        'user_id', 
        'title', 
        'source_url', 
        'file_path', 
        'duration', 
        'status'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class); //[cite: 202]
    }

    public function clips(): HasMany
    {
        return $this->hasMany(Clip::class); //
    }

    public function transcription()
    {
        return $this->hasOne(Transcription::class);
    }
}