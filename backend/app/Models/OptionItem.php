<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\OptionGroup;

class OptionItem extends Model
{
    protected $fillable = [
        'option_group_id', 'name', 'price', 'image_url', 'is_available'
    ];

    // O item pertence a um Grupo de Opções
    public function optionGroup(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class);
    }
}
