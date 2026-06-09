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

        $now = now(config('app.timezone', 'America/Fortaleza'));
        $today = $now->dayOfWeek;
        $currentTime = $now->format('H:i');

        $schedule = $this->operatingHours()
            ->where('day_of_week', $today)
            ->first();

        if ($schedule) {
            if ($schedule->is_closed) {
                return false;
            }

            return $this->timeIsWithinRange(
                $currentTime,
                $this->normalizeTime($schedule->opening_time),
                $this->normalizeTime($schedule->closing_time)
            );
        }

        $businessHours = $this->business_hours;

        if (!is_array($businessHours)) {
            return false;
        }

        $dayKey = [
            0 => 'sunday',
            1 => 'monday',
            2 => 'tuesday',
            3 => 'wednesday',
            4 => 'thursday',
            5 => 'friday',
            6 => 'saturday',
        ][$today] ?? null;

        $daySchedule = $dayKey ? ($businessHours[$dayKey] ?? null) : null;

        if (!$daySchedule || ($daySchedule['closed'] ?? false)) {
            return false;
        }

        return $this->timeIsWithinRange(
            $currentTime,
            $this->normalizeTime($daySchedule['open'] ?? null),
            $this->normalizeTime($daySchedule['close'] ?? null)
        );
    }

    public function getOpeningStatusAttribute(): array
    {
        if (!$this->is_open) {
            return [
                'is_open' => false,
                'message' => 'Loja fechada',
                'next_opening' => $this->nextOpening(),
            ];
        }

        if ($this->is_open_now) {
            return [
                'is_open' => true,
                'message' => 'Aberto agora',
                'next_opening' => null,
            ];
        }

        $nextOpening = $this->nextOpening();

        return [
            'is_open' => false,
            'message' => $this->nextOpeningMessage($nextOpening),
            'next_opening' => $nextOpening,
        ];
    }

    private function nextOpening(): ?array
    {
        $now = now(config('app.timezone', 'America/Fortaleza'));
        $currentTime = $now->format('H:i');

        for ($offset = 0; $offset < 7; $offset++) {
            $date = $now->addDays($offset);
            $schedule = $this->scheduleForDay($date->dayOfWeek);

            if (!$schedule || ($schedule['is_closed'] ?? false) || empty($schedule['opening_time'])) {
                continue;
            }

            $openingTime = $this->normalizeTime($schedule['opening_time']);

            if (!$openingTime) {
                continue;
            }

            if ($offset === 0 && $openingTime <= $currentTime) {
                continue;
            }

            return [
                'day_offset' => $offset,
                'day' => $date->dayOfWeek,
                'day_label' => $this->openingDayLabel($offset, $date->dayOfWeek),
                'date' => $date->toDateString(),
                'time' => $openingTime,
            ];
        }

        return null;
    }

    private function scheduleForDay(int $dayOfWeek): ?array
    {
        $schedule = $this->operatingHours()
            ->where('day_of_week', $dayOfWeek)
            ->first();

        if ($schedule) {
            return [
                'opening_time' => $schedule->opening_time,
                'closing_time' => $schedule->closing_time,
                'is_closed' => (bool) $schedule->is_closed,
            ];
        }

        $businessHours = $this->business_hours;

        if (!is_array($businessHours)) {
            return null;
        }

        $dayKey = [
            0 => 'sunday',
            1 => 'monday',
            2 => 'tuesday',
            3 => 'wednesday',
            4 => 'thursday',
            5 => 'friday',
            6 => 'saturday',
        ][$dayOfWeek] ?? null;

        $daySchedule = $dayKey ? ($businessHours[$dayKey] ?? null) : null;

        if (!$daySchedule) {
            return null;
        }

        return [
            'opening_time' => $daySchedule['open'] ?? null,
            'closing_time' => $daySchedule['close'] ?? null,
            'is_closed' => (bool) ($daySchedule['closed'] ?? false),
        ];
    }

    private function nextOpeningMessage(?array $nextOpening): string
    {
        if (!$nextOpening) {
            return 'Fechado hoje';
        }

        return 'Abre ' . $nextOpening['day_label'] . ' às ' . $nextOpening['time'];
    }

    private function openingDayLabel(int $offset, int $dayOfWeek): string
    {
        if ($offset === 0) {
            return 'hoje';
        }

        if ($offset === 1) {
            return 'amanhã';
        }

        return [
            0 => 'domingo',
            1 => 'segunda',
            2 => 'terça',
            3 => 'quarta',
            4 => 'quinta',
            5 => 'sexta',
            6 => 'sábado',
        ][$dayOfWeek] ?? 'em breve';
    }

    private function normalizeTime($time): ?string
    {
        if (!$time) {
            return null;
        }

        if ($time instanceof \DateTimeInterface) {
            return $time->format('H:i');
        }

        return substr((string) $time, 0, 5);
    }

    private function timeIsWithinRange(string $currentTime, ?string $openingTime, ?string $closingTime): bool
    {
        if (!$openingTime || !$closingTime) {
            return false;
        }

        if ($openingTime === $closingTime) {
            return true;
        }

        if ($openingTime < $closingTime) {
            return $currentTime >= $openingTime && $currentTime <= $closingTime;
        }

        return $currentTime >= $openingTime || $currentTime <= $closingTime;
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
