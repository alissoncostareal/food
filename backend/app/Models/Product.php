<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute; // IMPORTAÇÃO CORRETA
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'product_category_id',
        'name',
        'description',
        'price',
        'image',
        'slug'
    ];

    // Se você quer que o JSON sempre traga a URL completa da imagem
    protected $appends = ['image_url'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(OptionGroup::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /**
     * Acessor para a URL da imagem
     * Isso cria o campo 'image_url' no JSON
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->image
                ? asset('storage/' . $this->image)
                : asset('images/default-product.png')
        );
    }
}
