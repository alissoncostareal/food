<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $fillable = [
        'user_id',
        'new_order_sound_enabled',
        'new_order_sound_unlocked',
    ];

    protected $casts = [
        'new_order_sound_enabled' => 'boolean',
        'new_order_sound_unlocked' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
