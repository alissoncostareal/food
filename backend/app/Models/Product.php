<?php

namespace App\Models;

use App\Services\ImageService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'product_category_id',
        'ifood_item_id',
        'catalog_external_id',
        'name',
        'description',
        'price',
        'image',
        'slug',
        'is_active',
        'show_in_cart',
        'cart_highlight_order',
        'manage_stock',
        'stock_quantity',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'show_in_cart' => 'boolean',
        'cart_highlight_order' => 'integer',
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

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductCategory::class,
            'category_product',
            'product_id',
            'product_category_id'
        )->withTimestamps();
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->image) {
                    return ImageService::publicUrl($this->image);
                }

                $base = rtrim((string) (config('app.asset_url') ?: config('app.url')), '/');
                $url = $base.'/images/default-product.png';

                return app()->environment('production')
                    ? (preg_replace('/^http:\/\//i', 'https://', $url) ?? $url)
                    : $url;
            }
        );
    }
}
