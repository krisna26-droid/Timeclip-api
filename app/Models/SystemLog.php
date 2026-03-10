<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'service',
        'level',
        'category',
        'message',
        'payload',
        'user_id',
    ];

    /**
     * Otomatis konversi payload JSON menjadi array PHP saat diakses
     */
    protected $casts = [
        'payload' => 'array',
    ];

    /**
     * Relasi opsional ke User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}