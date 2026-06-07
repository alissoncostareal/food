<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Override;

class ProductCategory extends Model
{
    protected $fillable = [
        'store_id', // Adicione esta linha
        'name',
        'slug',
        'position'
    ];
    public function products()
    {

        return $this->hasMany(Product::class);
    }
}
