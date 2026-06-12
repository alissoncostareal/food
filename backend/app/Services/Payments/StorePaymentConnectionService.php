<?php

namespace App\Services\Payments;

use App\Models\Store;
use App\Models\StorePaymentProvider;

class StorePaymentConnectionService
{
    public function activePixProvider(Store $store): ?StorePaymentProvider
    {
        if ($store->relationLoaded('paymentPixProvider') && $store->paymentPixProvider?->isConnected()) {
            return $store->paymentPixProvider;
        }

        $provider = StorePaymentProvider::query()
            ->where('store_id', $store->id)
            ->where('is_active_for_pix', true)
            ->where('status', StorePaymentProvider::STATUS_CONNECTED)
            ->first();

        if ($provider && $store->payment_pix_provider_id !== $provider->id) {
            $store->forceFill(['payment_pix_provider_id' => $provider->id])->save();
        }

        return $provider;
    }

    public function paymentReady(Store $store): bool
    {
        return $this->activePixProvider($store) !== null;
    }

    public function providerCatalog(Store $store): array
    {
        $catalog = [];

        foreach (config('payments.providers', []) as $key => $provider) {
            $catalog[] = [
                'provider' => $key,
                'label' => $provider['label'] ?? $key,
                'description' => $provider['description'] ?? null,
                'webhook_url' => $this->webhookUrlFor($key, $store),
                'connection_methods' => collect($provider['connection_methods'] ?? [])
                    ->map(fn ($method, $methodKey) => [
                        'key' => $methodKey,
                        'label' => $method['label'] ?? $methodKey,
                        'fields' => $method['fields'] ?? [],
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $catalog;
    }

    public function connectionPayload(Store $store): array
    {
        $store->loadMissing('paymentPixProvider');

        $connections = StorePaymentProvider::query()
            ->where('store_id', $store->id)
            ->orderBy('provider')
            ->get()
            ->map(fn (StorePaymentProvider $item) => $item->publicPayload())
            ->all();

        $active = $this->activePixProvider($store);
        $pixOnlineActive = $store->online_payments_enabled
            && in_array(Store::PAYMENT_PIX_ONLINE, $store->acceptedPaymentMethods(), true);

        return [
            'status' => $active ? 'connected' : 'not_configured',
            'status_label' => $active ? 'Conta conectada' : 'Configure um gateway',
            'payment_ready' => $active !== null,
            'online_payments_enabled' => (bool) $store->online_payments_enabled,
            'pix_online_active' => $pixOnlineActive && $active !== null,
            'active_provider' => $active?->publicPayload(),
            'store_slug' => $store->slug,
            'providers_catalog' => $this->providerCatalog($store),
            'connections' => $connections,
            'accepted_payment_methods' => $store->acceptedPaymentMethods(),
        ];
    }

    public function activateForPix(Store $store, StorePaymentProvider $connection): StorePaymentProvider
    {
        if ((int) $connection->store_id !== (int) $store->id) {
            throw new \InvalidArgumentException('Conexão não pertence à loja.');
        }

        if (! $connection->isConnected()) {
            throw new \InvalidArgumentException('Conecte e valide o gateway antes de ativar.');
        }

        StorePaymentProvider::query()
            ->where('store_id', $store->id)
            ->update(['is_active_for_pix' => false]);

        $connection->forceFill(['is_active_for_pix' => true])->save();
        $store->forceFill(['payment_pix_provider_id' => $connection->id])->save();

        return $connection->fresh();
    }

    public function webhookUrlFor(string $provider, Store $store): string
    {
        return rtrim((string) config('app.url'), '/')
            .'/api/v1/webhooks/payments/'.$provider.'/'.ltrim((string) $store->slug, '/');
    }
}
