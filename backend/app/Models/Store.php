<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\OperatingHour;
use App\Models\ProductCategory;
use App\Models\DeliveryArea;

class Store extends Model
{
    use HasFactory;
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

    public function operatingHours()
    {
        return $this->hasMany(OperatingHour::class);
    }

    public function productCategories()
    {
        return $this->hasMany(ProductCategory::class)->orderBy('position');
    }

    public function deliveryAreas()
    {
        return $this->hasMany(DeliveryArea::class);
    }

    public function getIsOpenNowAttribute(): bool
    {
       // 1. Checa o botão manual (pânico)
        if (!$this->is_open) {
            return false;
        }

        // 2. Checa o relógio
        $now = now();
        $today = $now->dayOfWeek;
        $currentTime = $now->format('H:i:s');

        $schedule = $this->operatingHours()->where('day_of_week', $today)->first();

        if (!$schedule || $schedule->is_closed) {
            return false;
        }

        return $currentTime >= $schedule->opening_time && $currentTime <= $schedule->closing_time;
    }
}
