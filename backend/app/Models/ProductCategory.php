<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public static function resolveInsertPosition(int $storeId, ?int $requestedPosition): int
    {
        $count = static::where('store_id', $storeId)->count();

        if ($requestedPosition === null) {
            return $count;
        }

        return max(0, min($requestedPosition, $count));
    }

    public static function makeRoomAtPosition(int $storeId, int $position): void
    {
        static::where('store_id', $storeId)
            ->where('position', '>=', $position)
            ->increment('position');
    }

    public function reposition(?int $requestedPosition): void
    {
        if ($requestedPosition === null) {
            return;
        }

        $storeId = (int) $this->store_id;
        $maxIndex = static::where('store_id', $storeId)->count() - 1;
        $newPosition = max(0, min($requestedPosition, $maxIndex));
        $oldPosition = (int) $this->position;

        if ($newPosition === $oldPosition) {
            return;
        }

        if ($newPosition < $oldPosition) {
            static::where('store_id', $storeId)
                ->where('id', '!=', $this->id)
                ->where('position', '>=', $newPosition)
                ->where('position', '<', $oldPosition)
                ->increment('position');
        } else {
            static::where('store_id', $storeId)
                ->where('id', '!=', $this->id)
                ->where('position', '>', $oldPosition)
                ->where('position', '<=', $newPosition)
                ->decrement('position');
        }

        $this->position = $newPosition;
    }
}
