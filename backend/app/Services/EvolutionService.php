<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EvolutionService
{
    public function isConfigured(): bool
    {
        if ($this->isTestMode()) {
            return true;
        }

        return (bool) config('services.evolution.enabled')
            && filled(config('services.evolution.base_url'))
            && filled(config('services.evolution.api_key'));
    }

    public function isTestMode(): bool
    {
        return (bool) config('services.evolution.test_mode');
    }

    public function configurationStatus(): array
    {
        return [
            'enabled' => (bool) config('services.evolution.enabled'),
            'test_mode' => $this->isTestMode(),
            'configured' => $this->isConfigured(),
            'base_url' => config('services.evolution.base_url'),
            'default_instance' => config('services.evolution.default_instance'),
            'webhook_ready' => filled($this->resolveWebhookBaseUrl()),
        ];
    }

    public function instanceNameForStore(Store $store): string
    {
        return $store->evolution_instance_name ?: $store->slug;
    }

    public function webhookUrlForStore(Store $store): string
    {
        $base = rtrim($this->resolveWebhookBaseUrl(), '/');

        return "{$base}/api/v1/webhooks/evolution/{$store->slug}";
    }

    public function createInstance(Store $store): void
    {
        if ($this->isTestMode()) {
            Log::info('WhatsApp test mode: createInstance skipped', [
                'store_id' => $store->id,
                'instance' => $this->instanceNameForStore($store),
            ]);

            return;
        }

        $instanceName = $this->instanceNameForStore($store);

        $response = $this->client()->post('/instance/create', [
            'instanceName' => $instanceName,
            'qrcode' => true,
            'integration' => 'WHATSAPP-BAILEYS',
        ]);

        if ($this->instanceAlreadyExists($response)) {
            Log::info('Evolution instance already exists', [
                'store_id' => $store->id,
                'instance' => $instanceName,
            ]);

            return;
        }

        $response->throw();
    }

    public function configureWebhook(Store $store): void
    {
        if ($this->isTestMode()) {
            Log::info('WhatsApp test mode: webhook setup skipped', [
                'store_id' => $store->id,
                'url' => $this->webhookUrlForStore($store),
            ]);

            return;
        }

        $instanceName = $this->instanceNameForStore($store);

        $response = $this->client()->post("/webhook/set/{$instanceName}", [
            'webhook' => [
                'enabled' => true,
                'url' => $this->webhookUrlForStore($store),
                'webhookByEvents' => false,
                'events' => [
                    'MESSAGES_UPSERT',
                    'CONNECTION_UPDATE',
                ],
            ],
        ]);

        $response->throw();
    }

    public function fetchConnectionState(Store $store): ?string
    {
        if ($this->isTestMode()) {
            return 'open';
        }

        $instanceName = $this->instanceNameForStore($store);

        $response = $this->client()->get("/instance/connectionState/{$instanceName}");

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        return data_get($payload, 'instance.state')
            ?? data_get($payload, 'state')
            ?? data_get($payload, 'connectionStatus');
    }

    public function fetchQrCode(Store $store): ?array
    {
        $instanceName = $this->instanceNameForStore($store);

        $response = $this->client()->get("/instance/connect/{$instanceName}");

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        return [
            'pairing_code' => data_get($payload, 'pairingCode'),
            'code' => data_get($payload, 'code'),
            'base64' => data_get($payload, 'base64')
                ?? data_get($payload, 'qrcode.base64')
                ?? data_get($payload, 'qrcode'),
            'count' => data_get($payload, 'count'),
        ];
    }

    public function sendText(string $instanceName, string $number, string $text): void
    {
        if ($this->isTestMode()) {
            Log::info('WhatsApp test mode: message logged', [
                'instance' => $instanceName,
                'number' => $this->normalizePhone($number),
                'text' => $text,
            ]);

            return;
        }

        $response = $this->client()->post("/message/sendText/{$instanceName}", [
            'number' => $this->normalizePhone($number),
            'text' => $text,
        ]);

        $response->throw();
    }

    public function sendTextForStore(Store $store, string $number, string $text): void
    {
        $this->sendText($this->instanceNameForStore($store), $number, $text);
    }

    public function isConnectedState(?string $state): bool
    {
        return in_array(strtolower((string) $state), ['open', 'connected'], true);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(config('services.evolution.base_url'))
            ->timeout((int) config('services.evolution.timeout', 20))
            ->withHeaders([
                'apikey' => config('services.evolution.api_key'),
                'Content-Type' => 'application/json',
            ])
            ->acceptJson();
    }

    private function resolveWebhookBaseUrl(): string
    {
        $configured = trim((string) config('services.evolution.webhook_url'));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim((string) config('app.url'), '/');
    }

    private function instanceAlreadyExists(Response $response): bool
    {
        if ($response->successful()) {
            return false;
        }

        $message = Str::lower((string) (
            data_get($response->json(), 'message')
            ?? data_get($response->json(), 'error')
            ?? $response->body()
        ));

        return str_contains($message, 'already')
            || str_contains($message, 'exists')
            || str_contains($message, 'duplicate');
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 11) {
            return '55'.$digits;
        }

        if (strlen($digits) === 10) {
            return '55'.substr($digits, 0, 2).'9'.substr($digits, 2);
        }

        return $digits;
    }
}
