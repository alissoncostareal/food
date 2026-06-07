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
use Illuminate\Support\Str;

class Store extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'description',
        'instagram_link',
        'whatsapp_number',
        'primary_color',
        'address',
        'is_open',
        'delivery_fee',
        'logo_url',
        'banner_url',
        'business_hours',
        'slug'
    ];

    protected $casts = [
        'business_hours' => 'array',
        'is_open' => 'boolean',
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
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function operatingHours()
    {
        return $this->hasMany(OperatingHour::class);
    }

    public function productCategories()
    {
        return $this->hasMany(ProductCategory::class)->orderBy('position', 'asc');
    }

    public function deliveryAreas()
    {
        return $this->hasMany(DeliveryArea::class);
    }

    public function getIsOpenNowAttribute(): bool
    {
        if (!$this->is_open) return false;
        if (app()->environment('local')) {
            return true;
        }
        $now = now(); // Aqui o Laravel usa o timezone do config/app.php
        $today = $now->dayOfWeek;
        $currentTime = $now->format('H:i:s');

        $schedule = $this->operatingHours()
            ->where('day_of_week', $today)
            ->where('is_closed', false)
            ->first();

        if (!$schedule) {
            // Se cair aqui, é porque não achou o dia da semana no banco
            // Tente registrar no log para ver o que está acontecendo:
            // \Log::info("Dia: $today, Hora: $currentTime");
            return false;
        }

        return $currentTime >= $schedule->opening_time && $currentTime <= $schedule->closing_time;
    }
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
    protected static function booted()
    {
        static::creating(function ($store) {
            // Se não foi enviado um slug manual, gera a partir do nome
            if (empty($store->slug)) {
                $store->slug = Str::slug($store->name);
            } else {
                $store->slug = Str::slug($store->slug); // Garante que o slug manual esteja formatado correto
            }
        });

        static::updating(function ($store) {
            if ($store->isDirty('slug')) {
                // Remove espaços em branco nas pontas e força minúsculo, mas mantém o que ele digitou (incluindo _)
                $store->slug = strtolower(trim($store->slug));
            } elseif ($store->isDirty('name') && empty($store->slug)) {
                $store->slug = Str::slug($store->name, '-'); // Fallback automático se criar sem slug
            }
        });
    }
}
