<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OptionItem extends Model
{
    protected $fillable = [
        'option_group_id',
        'ifood_option_item_id',
        'catalog_external_id',
        'name',
        'price',
        'is_available',
        'image_url',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_available' => 'boolean',
    ];

    public function optionGroup(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class);
    }

    public function getImageAttribute(): ?string
    {
        $path = $this->getRawOriginal('image_url');

        return $path ? asset('storage/' . $path) : null;
    }
}
