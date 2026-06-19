<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\Store;

class ModuleMaintenance
{
    public const SETTING_KEY = 'module_maintenance';

    public const MODULES = [
        'dashboard' => 'Dashboard',
        'store' => 'Loja',
        'payments' => 'Recebimentos',
        'orders' => 'Pedidos',
        'products' => 'Cardápio',
        'categories' => 'Categorias',
        'coupons' => 'Cupons',
        'delivery_areas' => 'Áreas de entrega',
        'delivery_drivers' => 'Entregadores',
        'team' => 'Equipe',
        'reports' => 'Relatórios',
        'intelligence' => 'Inteligência',
        'import' => 'Importação',
        'whatsapp' => 'WhatsApp',
        'ifood' => 'iFood',
        'billing' => 'Meu plano',
        'settings' => 'Configurações',
    ];

    public const DEFAULT_MESSAGE = 'Este módulo está em manutenção. Tente novamente em breve.';

    public static function config(): array
    {
        $raw = PlatformSetting::get(self::SETTING_KEY);
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($decoded)) {
            return self::blankConfig();
        }

        return self::normalizeConfig($decoded);
    }

    public static function save(array $payload): array
    {
        $config = self::normalizeConfig($payload);
        PlatformSetting::set(self::SETTING_KEY, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $config;
    }

    public static function isBlocked(string $module, ?Store $store = null): bool
    {
        if (! array_key_exists($module, self::MODULES)) {
            return false;
        }

        $config = self::config();
        $moduleConfig = $config['modules'][$module] ?? [];

        if (! (bool) ($moduleConfig['maintenance'] ?? false)) {
            return false;
        }

        if ($store && self::storeHasBypass($store, $config)) {
            return false;
        }

        return true;
    }

    public static function messageFor(string $module): string
    {
        $config = self::config();
        $message = trim((string) ($config['modules'][$module]['message'] ?? ''));

        return $message !== '' ? $message : self::DEFAULT_MESSAGE;
    }

    /**
     * @return array<string, array{message: string}>
     */
    public static function activeModulesForStore(?Store $store): array
    {
        $active = [];

        foreach (array_keys(self::MODULES) as $module) {
            if (! self::isBlocked($module, $store)) {
                continue;
            }

            $active[$module] = [
                'message' => self::messageFor($module),
            ];
        }

        return $active;
    }

    public static function blankConfig(): array
    {
        $modules = [];

        foreach (array_keys(self::MODULES) as $module) {
            $modules[$module] = [
                'maintenance' => false,
                'message' => '',
            ];
        }

        return [
            'modules' => $modules,
            'bypass_store_ids' => [],
        ];
    }

    private static function normalizeConfig(array $payload): array
    {
        $blank = self::blankConfig();
        $modules = $blank['modules'];

        foreach ($payload['modules'] ?? [] as $module => $config) {
            if (! array_key_exists($module, $modules) || ! is_array($config)) {
                continue;
            }

            $modules[$module] = [
                'maintenance' => (bool) ($config['maintenance'] ?? false),
                'message' => trim((string) ($config['message'] ?? '')),
            ];
        }

        $bypassStoreIds = collect($payload['bypass_store_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        return [
            'modules' => $modules,
            'bypass_store_ids' => $bypassStoreIds,
        ];
    }

    private static function storeHasBypass(Store $store, array $config): bool
    {
        return in_array((int) $store->id, $config['bypass_store_ids'] ?? [], true);
    }
}
