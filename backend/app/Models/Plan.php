<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'max_products',
        'features',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'max_products' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
    ];

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) data_get($this->features ?? [], $feature, false);
    }

    public function hasUnlimitedProducts(): bool
    {
        return is_null($this->max_products);
    }
}
