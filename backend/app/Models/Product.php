<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'slug',
        'is_active',
        'manage_stock',
        'stock_quantity',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'manage_stock' => 'boolean',
        'stock_quantity' => 'integer',
        'price' => 'decimal:2',
    ];

    protected $appends = ['image_url'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(OptionGroup::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->image
                ? asset('storage/' . $this->image)
                : asset('images/default-product.png')
        );
    }
}
