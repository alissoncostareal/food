<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;
use App\Models\Product;

class Store extends Model
{
    protected $fillable = [
        'user_id', 'name', 'slug', 'logo_url', 'address', 'delivery_fee', 'is_open'
    ];

    // Uma loja pertence a um Utilizador (Dono)
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Uma loja possui muitos Produtos no cardápio
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
