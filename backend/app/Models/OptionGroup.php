<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Product;
use App\Models\OptionItem;

class OptionGroup extends Model
{
    protected $fillable = [
        'product_id', 'name', 'min_selected', 'max_selected'
    ];

    // O grupo pertence a um único Produto
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // O grupo possui vários Itens de Opção (ex: "Catupiry", "Cheddar")
    public function optionItems(): HasMany
    {
        return $this->hasMany(OptionItem::class);
    }
}
