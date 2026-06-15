<?php

namespace App\Services;

use App\Models\Store;
use App\Services\Payments\StorePaymentConnectionService;

class StoreSetupProgressService
{
    private const PLAN_TIER = [
        'trial' => 1,
        'starter' => 1,
        'pro' => 2,
        'premium' => 3,
    ];

    public function __construct(
        private readonly StorePaymentConnectionService $payments
    ) {}

    public function build(Store $store): array
    {
        $store->loadMissing(['plan', 'deliveryAreas', 'members', 'branches']);

        $planSlug = (string) ($store->plan?->slug ?? 'trial');
        $planTier = self::PLAN_TIER[$planSlug] ?? 1;

        $coreItems = $this->coreItems($store);
        $coreStats = $this->stats($coreItems);

        $planItems = $this->planItemsForTier($store, $planTier);
        $planStats = $this->stats($planItems);

        $upsell = $this->upsellPayload($store, $planTier, $planItems);

        return [
            'plan' => [
                'slug' => $planSlug,
                'name' => $store->plan?->name ?? 'Trial',
                'tier' => $planTier,
            ],
            'core' => [
                ...$coreStats,
                'label' => 'Configuração da loja',
                'items' => $coreItems,
            ],
            'plan_features' => [
                ...$planStats,
                'label' => "Recursos do plano {$store->plan?->name}",
                'items' => $planItems,
            ],
            'upsell' => $upsell,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function coreItems(Store $store): array
    {
        $definitions = [
            [
                'key' => 'name_slug',
                'label' => 'Nome e link do cardápio',
                'section' => 'identidade',
                'anchor' => 'setup-name',
            ],
            [
                'key' => 'description',
                'label' => 'Descrição da loja',
                'section' => 'identidade',
                'anchor' => 'setup-description',
            ],
            [
                'key' => 'address',
                'label' => 'Endereço',
                'section' => 'operacao',
                'anchor' => 'setup-address',
            ],
            [
                'key' => 'logo',
                'label' => 'Logo',
                'section' => 'visual',
                'anchor' => 'setup-logo',
            ],
            [
                'key' => 'banner',
                'label' => 'Banner',
                'section' => 'visual',
                'anchor' => 'setup-banner',
            ],
            [
                'key' => 'products',
                'label' => 'Produtos no cardápio',
                'section' => null,
                'route' => '/products',
            ],
            [
                'key' => 'hours',
                'label' => 'Horário de funcionamento',
                'section' => 'operacao',
                'anchor' => 'setup-hours',
            ],
            [
                'key' => 'payments',
                'label' => 'Formas de pagamento',
                'section' => 'operacao',
                'anchor' => 'setup-payments',
            ],
            [
                'key' => 'open',
                'label' => 'Loja aberta para pedidos',
                'section' => null,
                'anchor' => 'setup-store-status',
            ],
        ];

        return array_map(function (array $item) use ($store) {
            return [
                'key' => $item['key'],
                'label' => $item['label'],
                'section' => $item['section'] ?? null,
                'anchor' => $item['anchor'] ?? null,
                'done' => $this->isCoreItemDone($store, $item['key']),
                'required_plan' => null,
                'route' => $item['route'] ?? null,
            ];
        }, $definitions);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function planItemsForTier(Store $store, int $planTier): array
    {
        return array_values(array_filter(
            $this->extendedItemDefinitions($store),
            fn (array $item) => ($item['tier'] ?? 99) <= $planTier
                && ($item['applicable'] ?? true)
        ));
    }

    /**
     * @param list<array<string, mixed>> $currentPlanItems
     * @return array<string, mixed>|null
     */
    private function upsellPayload(Store $store, int $planTier, array $currentPlanItems): ?array
    {
        $nextTier = match (true) {
            $planTier >= 3 => null,
            $planTier >= 2 => 3,
            default => 2,
        };

        if ($nextTier === null) {
            return null;
        }

        $targetPlan = match ($nextTier) {
            2 => 'pro',
            3 => 'premium',
            default => 'pro',
        };

        $targetLabel = match ($nextTier) {
            2 => 'Pro',
            3 => 'Premium',
            default => 'Pro',
        };

        $currentKeys = collect($currentPlanItems)->pluck('key')->all();

        $items = array_values(array_filter(
            $this->extendedItemDefinitions($store),
            fn (array $item) => ($item['tier'] ?? 99) === $nextTier
                && ($item['applicable'] ?? true)
                && ! in_array($item['key'], $currentKeys, true)
        ));

        if ($items === []) {
            return null;
        }

        $pending = array_values(array_filter($items, fn (array $item) => ! $item['done']));

        return [
            'target_plan' => $targetPlan,
            'target_label' => $targetLabel,
            'completed' => count($items) - count($pending),
            'total' => count($items),
            'percent' => $this->percent(count($items) - count($pending), count($items)),
            'items' => $items,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extendedItemDefinitions(Store $store): array
    {
        $branchItemApplicable = $store->isMatriz() && $store->maxStoresAllowed() > 1;

        $definitions = [
            [
                'key' => 'online_pix',
                'label' => 'Pix online',
                'section' => 'operacao',
                'route' => '/payments',
                'required_plan' => 'pro',
                'tier' => 2,
                'applicable' => true,
            ],
            [
                'key' => 'delivery_areas',
                'label' => 'Áreas de entrega',
                'section' => 'operacao',
                'route' => '/delivery-areas',
                'required_plan' => 'pro',
                'tier' => 2,
                'applicable' => true,
            ],
            [
                'key' => 'whatsapp',
                'label' => 'WhatsApp automático',
                'section' => 'operacao',
                'route' => '/integrations/whatsapp',
                'required_plan' => 'pro',
                'tier' => 2,
                'applicable' => true,
            ],
            [
                'key' => 'ifood',
                'label' => 'Integração iFood',
                'section' => 'operacao',
                'route' => '/integrations/ifood',
                'required_plan' => 'premium',
                'tier' => 3,
                'applicable' => true,
            ],
            [
                'key' => 'team',
                'label' => 'Membro de equipe',
                'section' => 'operacao',
                'route' => '/team',
                'required_plan' => 'premium',
                'tier' => 3,
                'applicable' => true,
            ],
            [
                'key' => 'branches',
                'label' => 'Filial cadastrada',
                'section' => 'filiais',
                'route' => null,
                'required_plan' => 'premium',
                'tier' => 3,
                'applicable' => $branchItemApplicable,
            ],
        ];

        return array_map(function (array $item) use ($store) {
            return [
                'key' => $item['key'],
                'label' => $item['label'],
                'section' => $item['section'],
                'route' => $item['route'],
                'required_plan' => $item['required_plan'],
                'done' => $this->isExtendedItemDone($store, $item['key']),
            ];
        }, $definitions);
    }

    private function isCoreItemDone(Store $store, string $key): bool
    {
        return match ($key) {
            'name_slug' => filled($store->name) && filled($store->slug),
            'description' => filled($store->description) && mb_strlen(trim((string) $store->description)) >= 10,
            'address' => filled($store->address) && mb_strlen(trim((string) $store->address)) >= 5,
            'logo' => filled($store->logo_url),
            'banner' => filled($store->banner_url),
            'products' => $store->products()->exists(),
            'hours' => $this->hasConfiguredHours($store),
            'payments' => count($store->acceptedPaymentMethods()) >= 1,
            'open' => (bool) $store->is_open,
            default => false,
        };
    }

    private function isExtendedItemDone(Store $store, string $key): bool
    {
        return match ($key) {
            'online_pix' => (bool) $store->online_payments_enabled && $this->payments->paymentReady($store),
            'delivery_areas' => $store->deliveryAreas->isNotEmpty(),
            'whatsapp' => $store->evolution_status === WhatsappProvisioningService::STATUS_CONNECTED,
            'ifood' => $store->isIfoodConnected(),
            'team' => $store->members->isNotEmpty(),
            'branches' => $store->branches->isNotEmpty(),
            default => false,
        };
    }

    private function hasConfiguredHours(Store $store): bool
    {
        $hours = $store->business_hours;

        if (! is_array($hours) || $hours === []) {
            return false;
        }

        foreach ($hours as $day) {
            if (! is_array($day)) {
                continue;
            }

            if (! empty($day['closed'])) {
                continue;
            }

            if (! empty($day['all_day'])) {
                return true;
            }

            if (filled($day['open'] ?? null) && filled($day['close'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{completed: int, total: int, percent: int, complete: bool}
     */
    private function stats(array $items): array
    {
        $total = count($items);
        $completed = count(array_filter($items, fn (array $item) => $item['done']));

        return [
            'completed' => $completed,
            'total' => $total,
            'percent' => $this->percent($completed, $total),
            'complete' => $total > 0 && $completed === $total,
        ];
    }

    private function percent(int $completed, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) round(($completed / $total) * 100);
    }
}
