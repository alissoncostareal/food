<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Store;
use App\Models\OptionGroup;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'description',
        'price',
        'image_url',
        'store_id',
    ];

    protected $appends = ['image_url'];

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

    public function getImageUrlAttribute()
    {
        return $this->image ? asset('storage/' . $this->image) : asset('images/default-product.png');
    }
}
