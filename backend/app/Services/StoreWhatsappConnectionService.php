<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Facades\Log;
use Throwable;

class StoreWhatsappConnectionService
{
    public function __construct(
        private readonly WhatsappProvisioningService $evolutionProvisioning,
        private readonly MetaWhatsappProvisioningService $metaProvisioning,
        private readonly MetaWhatsappService $meta,
        private readonly EvolutionService $evolution,
    ) {}

    public function connectionPayload(Store $store, bool $refreshQr = true, bool $forceRefreshQr = false): array
    {
        $store->loadMissing('plan');
        $provider = $store->whatsappProvider();

        $payload = [
            'provider' => $provider,
            'provider_labels' => [
                Store::WHATSAPP_PROVIDER_EVOLUTION => 'Rápido (QR Code)',
                Store::WHATSAPP_PROVIDER_META => 'Oficial (Meta)',
            ],
            'features' => [
                'auto' => $store->canUseFeature('whatsapp_auto'),
                'bot' => $store->canUseFeature('whatsapp_bot'),
                'ai' => $store->canUseFeature('whatsapp_ai'),
            ],
            'bot' => [
                'enabled' => (bool) $store->whatsapp_bot_enabled,
                'ai_enabled' => $store->whatsappAiActive(),
            ],
            'evolution' => $this->evolution->configurationStatus(),
            'meta' => $this->meta->configurationStatus(),
            'status_notifications_require_customer_message' => true,
        ];

        if ($provider === Store::WHATSAPP_PROVIDER_META) {
            return array_merge($payload, $this->metaProvisioning->connectionPayload($store), [
                'test_mode' => $this->meta->isTestMode(),
            ]);
        }

        return array_merge($payload, $this->evolutionProvisioning->connectionPayload($store, $refreshQr, $forceRefreshQr));
    }

    public function setProvider(Store $store, string $provider): Store
    {
        if (! in_array($provider, [Store::WHATSAPP_PROVIDER_EVOLUTION, Store::WHATSAPP_PROVIDER_META], true)) {
            throw new \InvalidArgumentException('Provedor WhatsApp inválido.');
        }

        if ($provider === $store->whatsappProvider()) {
            return $store;
        }

        if ($provider === Store::WHATSAPP_PROVIDER_META) {
            $this->disconnectEvolutionIfConnected($store);
        } else {
            $this->metaProvisioning->disconnect($store);
        }

        $store->update([
            'whatsapp_provider' => $provider,
        ]);

        return $store->fresh(['plan']);
    }

    public function isConnected(Store $store): bool
    {
        if ($store->usesMetaWhatsapp()) {
            return $this->metaProvisioning->isConnected($store);
        }

        return $store->evolution_status === WhatsappProvisioningService::STATUS_CONNECTED
            || ($this->evolution->isTestMode() && $store->usesEvolutionWhatsapp());
    }

    private function disconnectEvolutionIfConnected(Store $store): void
    {
        if ($store->evolution_status !== WhatsappProvisioningService::STATUS_CONNECTED) {
            return;
        }

        try {
            $this->evolutionProvisioning->disconnectForNumberChange($store);
        } catch (Throwable $e) {
            Log::warning('Failed to disconnect Evolution when switching to Meta', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
