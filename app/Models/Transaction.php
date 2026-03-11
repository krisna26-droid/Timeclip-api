<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'external_id',
        'user_id',
        'tier_plan',
        'amount',
        'status',
        'snap_token'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
