<?php

namespace App\Services;

use App\Jobs\ProvisionEvolutionForStore;
use App\Models\Store;
use App\Support\IntegrationErrorReporter;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhatsappProvisioningService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_AWAITING_QR = 'awaiting_qr';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_ERROR = 'error';
    public const STATUS_DISABLED = 'disabled';

    public function __construct(
        private readonly EvolutionService $evolution
    ) {}

    public function queueProvisioningForMatriz(Store $matriz): void
    {
        $matriz->loadMissing(['plan', 'branches.plan']);

        foreach ($this->storesEligibleForProvisioning($matriz) as $store) {
            ProvisionEvolutionForStore::dispatch($store->id);
        }
    }

    public function syncAfterPlanChange(Store $matriz): void
    {
        $matriz->loadMissing(['plan', 'branches.plan']);

        foreach ($this->allManagedStores($matriz) as $store) {
            if ($store->canUseFeature('whatsapp_auto')) {
                ProvisionEvolutionForStore::dispatch($store->id);
                continue;
            }

            $this->disableStore($store);
        }
    }

    public function provision(Store $store): Store
    {
        $store->loadMissing('plan');

        if (! $store->canUseFeature('whatsapp_auto')) {
            return $store;
        }

        if (! $this->evolution->isConfigured()) {
            return $this->markError($store, 'Evolution API não configurada no servidor.');
        }

        if ($this->evolution->isTestMode()) {
            $store->update([
                'evolution_instance_name' => $this->evolution->instanceNameForStore($store),
                'evolution_status' => self::STATUS_CONNECTED,
                'evolution_connected_at' => now(),
                'evolution_last_error' => null,
            ]);

            return $store->fresh(['plan']);
        }

        if ($store->evolution_status === self::STATUS_CONNECTED) {
            return $store;
        }

        $store->update([
            'evolution_instance_name' => $this->evolution->instanceNameForStore($store),
            'evolution_status' => self::STATUS_PROVISIONING,
            'evolution_last_error' => null,
        ]);

        try {
            $this->evolution->createInstance($store);

            if ($store->canUseFeature('whatsapp_bot') && $store->whatsapp_bot_enabled) {
                $this->evolution->configureWebhook($store);
            }

            $store->update([
                'evolution_status' => self::STATUS_AWAITING_QR,
            ]);

            return $this->syncConnection($store->fresh(['plan']));
        } catch (Throwable $e) {
            Log::error('Evolution provisioning failed', [
                'store_id' => $store->id,
                'instance' => $store->evolution_instance_name,
                'error' => $e->getMessage(),
            ]);

            return $this->markError($store, $e, 'provision');
        }
    }

    public function syncConnection(Store $store): Store
    {
        if (! $this->evolution->isConfigured()) {
            return $store;
        }

        $state = $this->evolution->fetchConnectionState($store);

        if ($state === null) {
            if ($store->evolution_status === self::STATUS_CONNECTED) {
                $this->syncWhatsappNumberFromEvolution($store);
            }

            return $store->fresh(['plan']);
        }

        if ($this->evolution->isConnectedState($state)) {
            $this->syncWhatsappNumberFromEvolution($store);

            $store->update([
                'evolution_status' => self::STATUS_CONNECTED,
                'evolution_connected_at' => $store->evolution_connected_at ?? now(),
                'evolution_last_error' => null,
            ]);

            $store = $store->fresh(['plan']);

            if ($store->canUseFeature('whatsapp_bot') && $store->whatsapp_bot_enabled) {
                try {
                    $this->evolution->configureWebhook($store);
                } catch (Throwable $e) {
                    Log::warning('Failed to configure Evolution webhook on connect', [
                        'store_id' => $store->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $store;
        }

        if ($store->evolution_status === self::STATUS_CONNECTED
            && $this->evolution->isDisconnectedState($state)) {
            $store->update([
                'evolution_status' => self::STATUS_AWAITING_QR,
                'evolution_connected_at' => null,
            ]);
        }

        return $store->fresh(['plan']);
    }

    public function connectionPayload(Store $store, bool $refreshQr = true, bool $forceRefreshQr = false): array
    {
        $store->loadMissing('plan');
        $error = IntegrationErrorReporter::parseStored($store->evolution_last_error);
        $instanceName = $this->evolution->instanceNameForStore($store);

        $payload = [
            'instance_name' => $instanceName,
            'status' => $store->evolution_status ?: self::STATUS_PENDING,
            'connected_at' => $store->evolution_connected_at?->toIso8601String(),
            'last_error' => $error['message'],
            'error_ref' => $error['error_ref'],
            'whatsapp_number' => $store->whatsapp_number,
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
            'test_mode' => $this->evolution->isTestMode(),
        ];

        if (in_array($payload['status'], [self::STATUS_AWAITING_QR, self::STATUS_PROVISIONING], true)) {
            if ($refreshQr) {
                $payload['qrcode'] = $this->evolution->fetchQrCodeByName($instanceName, $forceRefreshQr);
            } else {
                $payload['qrcode'] = $this->evolution->cachedQrCodeByName($instanceName);
            }

            $payload['qrcode_expires_in'] = $this->evolution->qrCodeExpiresIn($instanceName);
        } else {
            $this->evolution->clearQrCache($instanceName);
        }

        return $payload;
    }

    public function disconnectForNumberChange(Store $store): Store
    {
        $store->loadMissing('plan');

        if (! $store->canUseFeature('whatsapp_auto')) {
            return $store;
        }

        if ($this->evolution->isTestMode()) {
            $store->update([
                'evolution_status' => self::STATUS_AWAITING_QR,
                'evolution_connected_at' => null,
                'evolution_last_error' => null,
                'whatsapp_number' => null,
            ]);

            return $store->fresh(['plan']);
        }

        if (! $this->evolution->isConfigured()) {
            throw new \RuntimeException('Evolution API não configurada no servidor.');
        }

        try {
            $this->evolution->logoutInstance($store);
        } catch (Throwable $e) {
            Log::warning('Evolution logout failed during number change', [
                'store_id' => $store->id,
                'instance' => $store->evolution_instance_name,
                'error' => $e->getMessage(),
            ]);
        }

        $store->update([
            'evolution_status' => self::STATUS_AWAITING_QR,
            'evolution_connected_at' => null,
            'evolution_last_error' => null,
            'whatsapp_number' => null,
        ]);

        return $store->fresh(['plan']);
    }

    private function syncWhatsappNumberFromEvolution(Store $store): void
    {
        $phone = $this->evolution->fetchInstanceOwnerPhone($store);

        if (filled($phone)) {
            $store->update(['whatsapp_number' => $phone]);
        }
    }

    private function storesEligibleForProvisioning(Store $matriz): array
    {
        return array_values(array_filter(
            $this->allManagedStores($matriz),
            fn (Store $store) => $store->canUseFeature('whatsapp_auto')
                && ! in_array($store->evolution_status, [self::STATUS_CONNECTED, self::STATUS_AWAITING_QR, self::STATUS_PROVISIONING], true)
        ));
    }

    private function allManagedStores(Store $matriz): array
    {
        $root = $matriz->matrizStore();

        if (! $root) {
            return [$matriz];
        }

        return array_merge(
            [$root],
            $root->branches()->with('plan')->get()->all()
        );
    }

    private function disableStore(Store $store): void
    {
        if (blank($store->evolution_instance_name) && blank($store->evolution_status)) {
            return;
        }

        $store->update([
            'evolution_status' => self::STATUS_DISABLED,
        ]);
    }

    private function markError(Store $store, Throwable|string $error, string $action = 'provision'): Store
    {
        $message = $error instanceof Throwable
            ? IntegrationErrorReporter::storeMessage('whatsapp', $action, $error, ['store_id' => $store->id])
            : (string) $error;

        $store->update([
            'evolution_status' => self::STATUS_ERROR,
            'evolution_last_error' => $message,
        ]);

        return $store->fresh(['plan']);
    }
}
