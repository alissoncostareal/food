<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    public const FEATURE_KEYS = [
        'coupons',
        'dashboard_advanced',
        'intelligence',
        'whatsapp_auto',
        'whatsapp_bot',
        'whatsapp_ai',
        'ifood_integration',
        'advanced_reports',
        'delivery_areas',
        'team',
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'max_products',
        'max_stores',
        'max_team_members',
        'features',
        'is_active',
        'is_visible',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'max_products' => 'integer',
        'max_stores' => 'integer',
        'max_team_members' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
        'is_visible' => 'boolean',
    ];

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) data_get($this->effectiveFeatures(), $feature, false);
    }

    public function effectiveFeatures(): array
    {
        $stored = is_array($this->features) ? $this->features : [];
        $features = self::blankFeatures();

        foreach (self::corePlanDefaults()[$this->slug] ?? [] as $key => $enabled) {
            $features[$key] = (bool) $enabled;
        }

        foreach ($stored as $key => $enabled) {
            if (array_key_exists($key, $features)) {
                $features[$key] = (bool) $enabled;
            }
        }

        if ($this->slug === 'premium' && !array_key_exists('intelligence', $stored)) {
            $features['intelligence'] = true;
        }

        if ($this->slug === 'premium' && !array_key_exists('team', $stored)) {
            $features['team'] = true;
        }

        return $features;
    }

    public static function blankFeatures(): array
    {
        return array_fill_keys(self::FEATURE_KEYS, false);
    }

    public static function corePlanDefaults(): array
    {
        return [
            'pro' => [
                'coupons' => true,
                'dashboard_advanced' => true,
                'whatsapp_auto' => true,
                'whatsapp_bot' => true,
                'delivery_areas' => true,
            ],
            'premium' => [
                'coupons' => true,
                'dashboard_advanced' => true,
                'intelligence' => true,
                'whatsapp_auto' => true,
                'whatsapp_bot' => true,
                'whatsapp_ai' => true,
                'ifood_integration' => true,
                'advanced_reports' => true,
                'delivery_areas' => true,
                'team' => true,
            ],
        ];
    }

    public function hasUnlimitedProducts(): bool
    {
        return is_null($this->max_products);
    }
}
