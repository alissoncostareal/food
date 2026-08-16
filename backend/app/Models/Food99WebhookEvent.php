<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Food99WebhookEvent extends Model
{
    protected $fillable = [
        'event_id',
        'event_type',
        'shop_id',
        'order_id',
        'status',
        'payload',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
