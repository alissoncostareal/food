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
    ];

    protected $casts = [
        'business_hours' => 'array',
        'is_open' => 'boolean',
        'subscription_ends_at' => 'datetime',
        'complimentary_until' => 'datetime',
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
        $maxProducts = $this->maxProductsAllowed();

        if (is_null($maxProducts)) {
            return false;
        }

        return $this->products()->count() >= $maxProducts;
    }

    public function getProductsUsageAttribute(): array
    {
        $maxProducts = $this->maxProductsAllowed();
        $currentProducts = $this->products()->count();

        return [
            'current' => $currentProducts,
            'limit' => $maxProducts,
            'is_unlimited' => is_null($maxProducts),
            'reached' => !is_null($maxProducts) && $currentProducts >= $maxProducts,
        ];
    }

    public function getIsOpenNowAttribute(): bool
    {
        if (!$this->is_open) {
            return false;
        }

        if (app()->environment('local')) {
            return true;
        }

        $now = now();
        $today = $now->dayOfWeek;
        $currentTime = $now->format('H:i:s');

        $schedule = $this->operatingHours()
            ->where('day_of_week', $today)
            ->where('is_closed', false)
            ->first();

        if (!$schedule) {
            return false;
        }

        return $currentTime >= $schedule->opening_time && $currentTime <= $schedule->closing_time;
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
