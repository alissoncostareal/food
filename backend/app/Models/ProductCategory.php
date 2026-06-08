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
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
