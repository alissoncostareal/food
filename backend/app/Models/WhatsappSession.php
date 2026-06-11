<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappSession extends Model
{
    public const STATE_IDLE = 'idle';

    public const STATE_HUMAN = 'human';

    protected $fillable = [
        'store_id',
        'customer_phone',
        'state',
        'context',
        'human_mode_until',
        'last_inbound_at',
        'last_outbound_at',
    ];

    protected $casts = [
        'context' => 'array',
        'human_mode_until' => 'datetime',
        'last_inbound_at' => 'datetime',
        'last_outbound_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappConversationMessage::class);
    }

    public function isHumanMode(): bool
    {
        if ($this->state !== self::STATE_HUMAN) {
            return false;
        }

        if ($this->human_mode_until && now()->gt($this->human_mode_until)) {
            return false;
        }

        return true;
    }

    public static function forStorePhone(Store $store, string $phone): self
    {
        return static::query()->firstOrCreate(
            [
                'store_id' => $store->id,
                'customer_phone' => $phone,
            ],
            [
                'state' => self::STATE_IDLE,
            ]
        );
    }
}
