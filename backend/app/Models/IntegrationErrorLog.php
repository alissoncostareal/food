<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationErrorLog extends Model
{
    protected $fillable = [
        'error_ref',
        'channel',
        'action',
        'store_id',
        'public_message',
        'details',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
