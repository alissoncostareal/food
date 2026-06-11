<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorePaymentProvider extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_ERROR = 'error';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'store_id',
        'provider',
        'connection_method',
        'credentials',
        'status',
        'account_label',
        'last_error',
        'is_active_for_pix',
        'connected_at',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'is_active_for_pix' => 'boolean',
        'connected_at' => 'datetime',
    ];

    protected $hidden = [
        'credentials',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        $credentials = $this->credentials;

        if (! is_array($credentials)) {
            return $default;
        }

        return $credentials[$key] ?? $default;
    }

    public function publicPayload(): array
    {
        $config = config("payments.providers.{$this->provider}", []);
        $methodConfig = data_get($config, "connection_methods.{$this->connection_method}", []);

        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'provider_label' => $config['label'] ?? $this->provider,
            'description' => $config['description'] ?? null,
            'connection_method' => $this->connection_method,
            'connection_method_label' => $methodConfig['label'] ?? $this->connection_method,
            'status' => $this->status,
            'status_label' => match ($this->status) {
                self::STATUS_CONNECTED => 'Conectado',
                self::STATUS_ERROR => 'Erro',
                self::STATUS_DISABLED => 'Desativado',
                default => 'Pendente',
            },
            'account_label' => $this->account_label,
            'last_error' => $this->last_error,
            'is_active_for_pix' => $this->is_active_for_pix,
            'connected_at' => $this->connected_at,
            'fields' => $methodConfig['fields'] ?? [],
            'has_credentials' => filled($this->credentials),
        ];
    }
}
