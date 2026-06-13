<?php

namespace App\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'price',
        'subtotal',
        'options',
        'observation',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'options' => 'array',
    ];

    protected $appends = [
        'options_list',
        'grouped_options',
    ];

    public function getOptionsAttribute($value): array
    {
        return $this->normalizeOptions($this->decodeOptionsValue($value));
    }

    private function decodeOptionsValue(mixed $value): array
    {
        if (blank($value)) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (is_string($decoded)) {
            $nested = json_decode($decoded, true);

            return is_array($nested) ? $nested : [];
        }

        return [];
    }

    private function normalizeOptions(array $options): array
    {
        if ($options === []) {
            return [];
        }

        if (! array_is_list($options)) {
            $options = array_values($options);
        }

        return collect($options)
            ->filter(fn ($option) => is_array($option) || is_string($option))
            ->map(function ($option) {
                if (is_string($option)) {
                    return [
                        'name' => $option,
                        'group_name' => 'Adicionais',
                        'additional_price' => 0.0,
                    ];
                }

                return [
                    'name' => $option['name']
                        ?? $option['label']
                        ?? $option['title']
                        ?? 'Opção',
                    'group_name' => $option['group_name']
                        ?? $option['groupName']
                        ?? $option['group']
                        ?? $option['category']
                        ?? 'Adicionais',
                    'additional_price' => (float) (
                        $option['additional_price']
                        ?? $option['price']
                        ?? $option['amount']
                        ?? $option['unitPrice']
                        ?? 0
                    ),
                ];
            })
            ->values()
            ->all();
    }

    public function getOptionsListAttribute(): array
    {
        return $this->options;
    }

    public function getGroupedOptionsAttribute(): array
    {
        return collect($this->options)
            ->filter()
            ->groupBy(function ($option) {
                return $option['group_name']
                    ?? $option['group']
                    ?? $option['category']
                    ?? 'Adicionais';
            })
            ->map(function ($options) {
                return $options->map(function ($option) {
                    return [
                        'name' => $option['name']
                            ?? $option['label']
                            ?? $option['title']
                            ?? 'Opção',
                        'group_name' => $option['group_name']
                            ?? $option['group']
                            ?? $option['category']
                            ?? 'Adicionais',
                        'additional_price' => (float) (
                            $option['additional_price']
                            ?? $option['price']
                            ?? $option['amount']
                            ?? 0
                        ),
                    ];
                })->values();
            })
            ->toArray();
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
