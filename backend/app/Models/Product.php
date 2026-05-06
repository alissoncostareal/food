<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Store;
use App\Models\OptionGroup;

class Product extends Model
{
    protected $fillable = [
        'store_id', 'name', 'description', 'price', 'image_url', 'is_available'
    ];

    // O produto pertence a uma Loja
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    // O produto pode ter vários Grupos de Opções (ex: Bordas, Bebidas)
    public function optionGroups(): HasMany
    {
        return $this->hasMany(OptionGroup::class);
    }
}
