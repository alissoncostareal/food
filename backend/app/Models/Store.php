<?php

namespace App\Models;

use App\Models\DeliveryArea;
use App\Models\OperatingHour;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
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
        'slug',
        'plan_id',
        'plan_type',
        'subscription_status',
        'subscription_ends_at',
        'complimentary_until',
        'complimentary_reason',
        'billing_email',
        'mercado_pago_preapproval_id',
        'mercado_pago_subscription_status',
        'mercado_pago_last_payment_id',
        'mercado_pago_last_payment_at',
    ];

    protected $casts = [
        'business_hours' => 'array',
        'is_open' => 'boolean',
        'subscription_ends_at' => 'datetime',
        'complimentary_until' => 'datetime',
        'mercado_pago_last_payment_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(OperatingHour::class);
    }

    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class)->orderBy('position', 'asc');
    }

    public function deliveryAreas(): HasMany
    {
        return $this->hasMany(DeliveryArea::class);
    }

    public function hasActiveSubscription(): bool
    {
        if ($this->subscription_status === 'complimentary') {
            return is_null($this->complimentary_until) || now()->lte($this->complimentary_until);
        }

        if (in_array($this->subscription_status, ['active', 'trial'], true)) {
            return is_null($this->subscription_ends_at) || now()->lte($this->subscription_ends_at);
        }

        return false;
    }

    public function hasFeature(string $feature): bool
    {
        if (!$this->relationLoaded('plan')) {
            $this->load('plan');
        }

        return $this->plan?->hasFeature($feature) ?? false;
    }

    public function canUseFeature(string $feature): bool
    {
        return $this->hasActiveSubscription() && $this->hasFeature($feature);
    }

    public function maxProductsAllowed(): ?int
    {
        if (!$this->relationLoaded('plan')) {
            $this->load('plan');
        }

        return $this->plan?->max_products;
    }

    public function productsLimitReached(): bool
    {
        $limit = $this->maxProductsAllowed();

        if (is_null($limit)) {
            return false;
        }

        return $this->products()->count() >= $limit;
    }

    public function getIsOpenNowAttribute(): bool
    {
        return $this->opening_status['is_open'] ?? false;
    }

    public function getOpeningStatusAttribute(): array
    {
        if (!$this->is_open) {
            return [
                'is_open' => false,
                'message' => 'Loja fechada manualmente.',
                'next_opening' => null,
            ];
        }

        if (app()->environment('local')) {
            return [
                'is_open' => true,
                'message' => 'Aberto agora',
                'next_opening' => null,
            ];
        }

        $now = now();
        $today = $now->dayOfWeek;
        $currentTime = $now->format('H:i:s');

        $schedule = $this->operatingHours()
            ->where('day_of_week', $today)
            ->where('is_closed', false)
            ->first();

        if (!$schedule) {
            return [
                'is_open' => false,
                'message' => 'Fechado hoje',
                'next_opening' => null,
            ];
        }

        if ($currentTime >= $schedule->opening_time && $currentTime <= $schedule->closing_time) {
            return [
                'is_open' => true,
                'message' => 'Aberto agora',
                'next_opening' => null,
            ];
        }

        return [
            'is_open' => false,
            'message' => 'Fechado no momento',
            'next_opening' => null,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        static::creating(function ($store) {
            if (empty($store->slug)) {
                $store->slug = Str::slug($store->name);
            } else {
                $store->slug = Str::slug($store->slug);
            }
        });

        static::updating(function ($store) {
            if ($store->isDirty('slug')) {
                $store->slug = strtolower(trim($store->slug));
            } elseif ($store->isDirty('name') && empty($store->slug)) {
                $store->slug = Str::slug($store->name, '-');
            }
        });
    }
}
