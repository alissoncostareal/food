<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\OptionGroup;

class OptionItem extends Model
{
    protected $fillable = [
        'option_group_id', 'name', 'price', 'is_available'
    ];
    protected $appends = ['image_url'];

    public function optionGroup(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class);
    }
    public function getImageUrlAttribute()
    {
        if ($this->image) {
            return asset('storage/' . $this->image);
        }

        return null;
    }
}
