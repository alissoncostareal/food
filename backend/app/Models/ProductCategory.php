<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductCategory extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'slug',
        'position',
        'ifood_category_id',
        'catalog_external_id',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
