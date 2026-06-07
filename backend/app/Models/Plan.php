<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Store;

class Plan extends Model
{
    protected $fillable = [
    'name',
    'slug',
    'user_id',
    'plan_id',
    'subscription_status',
    'subscription_ends_at'
    ];
    protected $casts = [
    'subscription_ends_at' => 'datetime',
    ];

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }
}
