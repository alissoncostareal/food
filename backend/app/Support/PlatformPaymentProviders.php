<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\Store;

class PlatformPaymentProviders
{
    public const SETTING_KEY = 'payment_providers_enabled';

    public static function allProviderKeys(): array
    {
        return array_keys(config('payments.providers', []));
    }

    public static function enabledKeys(): array
    {
        $raw = PlatformSetting::get(self::SETTING_KEY);
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($decoded) || $decoded === []) {
            return self::allProviderKeys();
        }

        return array_values(array_intersect(
            collect($decoded)->map(fn ($key) => (string) $key)->all(),
            self::allProviderKeys()
        ));
    }

    /**
     * @param  array<int, string>  $providerKeys
     */
    public static function saveEnabled(array $providerKeys): array
    {
        $enabled = array_values(array_unique(array_intersect(
            collect($providerKeys)->map(fn ($key) => (string) $key)->all(),
            self::allProviderKeys()
        )));

        PlatformSetting::set(self::SETTING_KEY, json_encode($enabled, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $enabled;
    }

    public static function storeBypasses(Store $store): bool
    {
        $demoSlugs = collect(config('services.demo_store_slugs', ['lojademo']))
            ->map(fn ($slug) => strtolower(trim((string) $slug)))
            ->filter()
            ->all();

        if (in_array(strtolower((string) $store->slug), $demoSlugs, true)) {
            return true;
        }

        return ModuleMaintenance::storeHasBypass($store);
    }

    public static function isAvailable(string $provider, ?Store $store = null): bool
    {
        if (! in_array($provider, self::allProviderKeys(), true)) {
            return false;
        }

        if ($store && self::storeBypasses($store)) {
            return true;
        }

        return in_array($provider, self::enabledKeys(), true);
    }

    /**
     * @return array<int, array{key: string, label: string, enabled: bool, supports_credit_card: bool}>
     */
    public static function adminPayload(): array
    {
        $enabled = self::enabledKeys();

        return collect(config('payments.providers', []))
            ->map(function (array $provider, string $key) use ($enabled) {
                return [
                    'key' => $key,
                    'label' => (string) ($provider['label'] ?? $key),
                    'description' => (string) ($provider['description'] ?? ''),
                    'enabled' => in_array($key, $enabled, true),
                    'supports_credit_card' => $key === 'pagarme',
                ];
            })
            ->values()
            ->all();
    }

    public static function demoStoreSlugs(): array
    {
        return collect(config('services.demo_store_slugs', ['lojademo']))
            ->map(fn ($slug) => (string) $slug)
            ->filter()
            ->values()
            ->all();
    }
}
