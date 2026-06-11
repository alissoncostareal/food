<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class IfoodOrderActions
{
    public function __construct(
        private readonly IfoodService $ifood
    ) {
    }

    public function confirm(Store $store, string $ifoodOrderId): void
    {
        $this->post($store, $ifoodOrderId, 'confirm');
    }

    public function startPreparation(Store $store, string $ifoodOrderId): void
    {
        $this->post($store, $ifoodOrderId, 'startPreparation');
    }

    public function readyToPickup(Store $store, string $ifoodOrderId): void
    {
        $this->post($store, $ifoodOrderId, 'readyToPickup');
    }

    public function dispatch(Store $store, string $ifoodOrderId): void
    {
        $this->post($store, $ifoodOrderId, 'dispatch', [
            'deliveredBy' => 'MERCHANT',
        ]);
    }

    public function verifyDeliveryCode(Store $store, string $ifoodOrderId, string $code): void
    {
        $this->post($store, $ifoodOrderId, 'verifyDeliveryCode', [
            'code' => $code,
        ]);
    }

    /**
     * @return array<int, array{code: string, description: string}>
     */
    public function cancellationReasons(Store $store, string $ifoodOrderId): array
    {
        $token = $this->ifood->accessTokenForStore($store);
        $baseUrl = rtrim(config('services.ifood.base_url', 'https://merchant-api.ifood.com.br'), '/');

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->get("{$baseUrl}/order/v1.0/orders/{$ifoodOrderId}/cancellationReasons");

        if ($response->failed()) {
            throw new RuntimeException($this->formatError($response->json()) ?: 'Não foi possível buscar motivos de cancelamento.');
        }

        $reasons = $response->json();

        if (! is_array($reasons)) {
            return [];
        }

        return collect($reasons)
            ->map(fn ($reason) => [
                'code' => (string) (data_get($reason, 'cancelCodeId') ?: data_get($reason, 'code') ?: ''),
                'description' => (string) (data_get($reason, 'description') ?: data_get($reason, 'reason') ?: 'Motivo'),
            ])
            ->filter(fn (array $reason) => $reason['code'] !== '')
            ->values()
            ->all();
    }

    public function requestCancellation(Store $store, string $ifoodOrderId, string $reasonCode): void
    {
        $this->post($store, $ifoodOrderId, 'requestCancellation', [
            'reason' => $reasonCode,
            'cancellationCode' => $reasonCode,
        ]);
    }

    private function post(Store $store, string $ifoodOrderId, string $action, array $body = []): void
    {
        $token = $this->ifood->accessTokenForStore($store);
        $baseUrl = rtrim(config('services.ifood.base_url', 'https://merchant-api.ifood.com.br'), '/');

        $request = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20));

        $response = $body === []
            ? $request->post("{$baseUrl}/order/v1.0/orders/{$ifoodOrderId}/{$action}")
            : $request->post("{$baseUrl}/order/v1.0/orders/{$ifoodOrderId}/{$action}", $body);

        if ($response->successful() || in_array($response->status(), [202, 204], true)) {
            Log::info('iFood order action ok', [
                'action' => $action,
                'ifood_order_id' => $ifoodOrderId,
                'store_id' => $store->id,
                'status' => $response->status(),
            ]);

            return;
        }

        if ($response->status() === 409 && in_array($action, ['confirm', 'startPreparation', 'readyToPickup', 'dispatch', 'verifyDeliveryCode'], true)) {
            Log::info('iFood order action already applied', [
                'action' => $action,
                'ifood_order_id' => $ifoodOrderId,
                'store_id' => $store->id,
            ]);

            return;
        }

        Log::warning('iFood order action failed', [
            'action' => $action,
            'ifood_order_id' => $ifoodOrderId,
            'store_id' => $store->id,
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        throw new RuntimeException($this->formatError($response->json()) ?: "Ação iFood \"{$action}\" falhou.");
    }

    private function formatError(?array $error): string
    {
        if (! is_array($error)) {
            return 'Erro na comunicação com o iFood.';
        }

        return (string) (
            data_get($error, 'description')
            ?: data_get($error, 'message')
            ?: data_get($error, 'error.message')
            ?: data_get($error, 'error_description')
            ?: 'Erro na comunicação com o iFood.'
        );
    }
}
