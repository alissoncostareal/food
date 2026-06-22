<?php

namespace App\Services;

use App\Models\Store;
use App\Support\IntegrationErrorReporter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class EvolutionService
{
    private const QR_CACHE_TTL_SECONDS = 45;
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
        $missing = [];

        if (! (bool) config('services.evolution.enabled')) {
            $missing[] = 'EVOLUTION_ENABLED';
        }

        if (! filled(config('services.evolution.base_url'))) {
            $missing[] = 'EVOLUTION_API_URL';
        }

        if (! filled(config('services.evolution.api_key'))) {
            $missing[] = 'EVOLUTION_API_KEY';
        }

        return [
            'enabled' => (bool) config('services.evolution.enabled'),
            'test_mode' => $this->isTestMode(),
            'configured' => $this->isConfigured(),
            'missing' => $missing,
            'base_url' => config('services.evolution.base_url'),
            'default_instance' => config('services.evolution.default_instance'),
            'webhook_ready' => filled($this->resolveWebhookBaseUrl()),
            'webhook_url_missing' => ! filled($this->resolveWebhookBaseUrl()),
        ];
    }

    public function defaultInstanceName(): ?string
    {
        $name = trim((string) config('services.evolution.default_instance'));

        return $name !== '' ? $name : null;
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
        $this->createInstanceByName($this->instanceNameForStore($store), [
            'store_id' => $store->id,
        ]);
    }

    public function createInstanceByName(string $instanceName, array $logContext = []): void
    {
        if ($this->isTestMode()) {
            Log::info('WhatsApp test mode: createInstance skipped', array_merge($logContext, [
                'instance' => $instanceName,
            ]));

            return;
        }

        if ($this->instanceExists($instanceName)) {
            Log::info('Evolution instance already exists', array_merge($logContext, [
                'instance' => $instanceName,
            ]));

            return;
        }

        $response = $this->client(provision: true)->post('/instance/create', [
            'instanceName' => $instanceName,
            'qrcode' => true,
            'integration' => 'WHATSAPP-BAILEYS',
        ]);

        if ($this->instanceAlreadyExists($response)) {
            Log::info('Evolution instance already exists', array_merge($logContext, [
                'instance' => $instanceName,
            ]));

            return;
        }

        $response->throw();
    }

    public function instanceExists(string $instanceName): bool
    {
        return $this->findInstanceRecord($instanceName) !== null;
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

        $webhook = [
            'enabled' => true,
            'url' => $this->webhookUrlForStore($store),
            'webhookByEvents' => false,
            'events' => [
                'MESSAGES_UPSERT',
                'CONNECTION_UPDATE',
            ],
        ];

        $secret = trim((string) config('services.evolution.webhook_secret'));
        if ($secret !== '') {
            $webhook['headers'] = [
                'x-evolution-secret' => $secret,
            ];
        }

        $response = $this->client()->post("/webhook/set/{$instanceName}", [
            'webhook' => $webhook,
        ]);

        $response->throw();
    }

    public function fetchConnectionState(Store $store): ?string
    {
        return $this->fetchConnectionStateByName($this->instanceNameForStore($store));
    }

    public function fetchConnectionStateByName(string $instanceName): ?string
    {
        if ($this->isTestMode()) {
            return 'open';
        }

        $cacheKey = $this->stateCacheKey($instanceName);

        try {
            $response = $this->client()->get("/instance/connectionState/{$instanceName}");

            if (! $response->successful()) {
                return Cache::get($cacheKey);
            }

            $payload = $response->json();

            $state = data_get($payload, 'instance.state')
                ?? data_get($payload, 'state')
                ?? data_get($payload, 'connectionStatus');

            if (filled($state)) {
                Cache::put($cacheKey, $state, 60);
            }

            return $state;
        } catch (Throwable $e) {
            Log::warning('Evolution connectionState failed', [
                'instance' => $instanceName,
                'error' => $e->getMessage(),
            ]);

            return Cache::get($cacheKey);
        }
    }

    public function isDisconnectedState(?string $state): bool
    {
        return in_array(strtolower((string) $state), ['close', 'closed', 'disconnected', 'logout'], true);
    }

    public function fetchQrCode(Store $store): ?array
    {
        return $this->fetchQrCodeByName($this->instanceNameForStore($store));
    }

    public function fetchQrCodeByName(string $instanceName, bool $forceRefresh = false): ?array
    {
        $cacheKey = $this->qrCacheKey($instanceName);

        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);

            if (is_array($cached) && $this->hasQrPayload($cached)) {
                return $cached;
            }
        }

        try {
            $response = $this->client(provision: true)->get("/instance/connect/{$instanceName}");

            if (! $response->successful()) {
                Log::warning('Evolution QR fetch failed', [
                    'instance' => $instanceName,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return Cache::get($cacheKey);
            }

            $payload = $response->json();
        } catch (Throwable $e) {
            Log::warning('Evolution QR fetch failed', [
                'instance' => $instanceName,
                'error' => $e->getMessage(),
            ]);

            return Cache::get($cacheKey);
        }

        $qr = [
            'pairing_code' => data_get($payload, 'pairingCode')
                ?? data_get($payload, 'pairing_code')
                ?? data_get($payload, 'qrcode.pairingCode'),
            'code' => data_get($payload, 'code')
                ?? data_get($payload, 'qrcode.code'),
            'base64' => data_get($payload, 'base64')
                ?? data_get($payload, 'qrcode.base64')
                ?? (is_string(data_get($payload, 'qrcode')) ? data_get($payload, 'qrcode') : null)
                ?? data_get($payload, 'instance.qrcode.base64'),
            'count' => data_get($payload, 'count')
                ?? data_get($payload, 'qrcode.count'),
            'cached_at' => now()->toIso8601String(),
            'expires_in' => self::QR_CACHE_TTL_SECONDS,
        ];

        if ($this->hasQrPayload($qr)) {
            Cache::put($cacheKey, $qr, self::QR_CACHE_TTL_SECONDS);

            return $qr;
        }

        Log::warning('Evolution QR response missing image/code', [
            'instance' => $instanceName,
            'payload' => $payload,
        ]);

        return Cache::get($cacheKey);
    }

    public function cachedQrCodeByName(string $instanceName): ?array
    {
        $cached = Cache::get($this->qrCacheKey($instanceName));

        return is_array($cached) && $this->hasQrPayload($cached) ? $cached : null;
    }

    public function qrCodeExpiresIn(string $instanceName): ?int
    {
        $cached = $this->cachedQrCodeByName($instanceName);

        if (! $cached || blank($cached['cached_at'] ?? null)) {
            return null;
        }

        $elapsed = now()->diffInSeconds($cached['cached_at']);
        $ttl = (int) ($cached['expires_in'] ?? self::QR_CACHE_TTL_SECONDS);

        return max(0, $ttl - $elapsed);
    }

    public function clearQrCache(string $instanceName): void
    {
        Cache::forget($this->qrCacheKey($instanceName));
    }

    private function qrCacheKey(string $instanceName): string
    {
        return 'evolution_qr:'.Str::slug($instanceName);
    }

    private function hasQrPayload(array $qr): bool
    {
        return filled($qr['base64'] ?? null)
            || filled($qr['code'] ?? null)
            || filled($qr['pairing_code'] ?? null);
    }

    public function logoutInstance(Store $store): void
    {
        $this->logoutInstanceByName($this->instanceNameForStore($store), [
            'store_id' => $store->id,
        ]);
    }

    public function logoutInstanceByName(string $instanceName, array $logContext = []): void
    {
        if ($this->isTestMode()) {
            $this->clearQrCache($instanceName);

            Log::info('WhatsApp test mode: logout skipped', array_merge($logContext, [
                'instance' => $instanceName,
            ]));

            return;
        }

        $response = $this->client()->delete("/instance/logout/{$instanceName}");

        if (in_array($response->status(), [404, 400], true)) {
            $this->clearQrCache($instanceName);

            return;
        }

        $response->throw();
        $this->clearQrCache($instanceName);
    }

    public function fetchInstanceOwnerPhone(Store $store): ?string
    {
        return $this->fetchInstanceOwnerPhoneByName($this->instanceNameForStore($store));
    }

    public function fetchInstanceOwnerPhoneByName(string $instanceName): ?string
    {
        if ($this->isTestMode()) {
            return null;
        }

        $record = $this->findInstanceRecord($instanceName);

        if (! $record) {
            return null;
        }

        return $this->extractOwnerPhoneFromInstanceRecord($record);
    }

    public function isInstanceReadyForMessaging(string $instanceName): bool
    {
        if ($this->isTestMode()) {
            return true;
        }

        $record = $this->findInstanceRecord($instanceName);

        if ($record) {
            $status = strtolower((string) (
                data_get($record, 'connectionStatus')
                ?? data_get($record, 'status')
                ?? data_get($record, 'instance.status')
                ?? data_get($record, 'instance.connectionStatus')
                ?? data_get($record, 'state')
                ?? data_get($record, 'instance.state')
            ));

            if ($this->isConnectedState($status)) {
                return true;
            }

            if ($this->isDisconnectedState($status)) {
                return false;
            }
        }

        return $this->isConnectedState($this->fetchConnectionStateByName($instanceName));
    }

    public function findInstanceRecord(string $instanceName): ?array
    {
        if ($this->isTestMode()) {
            return null;
        }

        foreach ([
            ['instanceName' => $instanceName],
            [],
        ] as $query) {
            try {
                $response = $this->client()->get('/instance/fetchInstances', $query);

                if (! $response->successful()) {
                    continue;
                }

                $match = $this->matchInstanceRecord($response->json(), $instanceName);

                if ($match) {
                    return $match;
                }
            } catch (Throwable $e) {
                Log::warning('Evolution fetchInstances failed', [
                    'instance' => $instanceName,
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    private function matchInstanceRecord(mixed $payload, string $instanceName): ?array
    {
        foreach ($this->normalizeInstancesPayload($payload) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->instanceNameFromRecord($item);

            if ($name !== null && strcasecmp($name, $instanceName) === 0) {
                return $item;
            }
        }

        return null;
    }

    private function normalizeInstancesPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (isset($payload['response']) && is_array($payload['response'])) {
            return $this->normalizeInstancesPayload($payload['response']);
        }

        if (isset($payload['instances']) && is_array($payload['instances'])) {
            return $this->normalizeInstancesPayload($payload['instances']);
        }

        if (isset($payload['instance']) && is_array($payload['instance'])) {
            return [$payload];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        if ($this->instanceNameFromRecord($payload) !== null) {
            return [$payload];
        }

        return [];
    }

    private function instanceNameFromRecord(array $item): ?string
    {
        $name = data_get($item, 'name')
            ?? data_get($item, 'instanceName')
            ?? data_get($item, 'instance.instanceName')
            ?? data_get($item, 'instance.name');

        return filled($name) ? (string) $name : null;
    }

    private function extractOwnerPhoneFromInstanceRecord(array $item): ?string
    {
        $candidates = [
            data_get($item, 'owner'),
            data_get($item, 'number'),
            data_get($item, 'ownerJid'),
            data_get($item, 'wuid'),
            data_get($item, 'instance.owner'),
            data_get($item, 'instance.number'),
            data_get($item, 'instance.ownerJid'),
            data_get($item, 'instance.wuid'),
            data_get($item, 'instance.profileNumber'),
        ];

        foreach ($candidates as $candidate) {
            $phone = $this->phoneFromWhatsappId(is_string($candidate) ? $candidate : null);

            if ($phone) {
                return $phone;
            }
        }

        return null;
    }

    public function formatPhoneForDisplay(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 11) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7));
        }

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6));
        }

        return $digits !== '' ? $digits : null;
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

        try {
            $response = $this->client(sendMessage: true)->post("/message/sendText/{$instanceName}", [
                'number' => $this->normalizePhone($number),
                'text' => $text,
            ]);

            if ($response->successful()) {
                return;
            }

            throw new \RuntimeException($this->responseErrorMessage($response));
        } catch (Throwable $e) {
            Log::warning('Evolution sendText failed', [
                'instance' => $instanceName,
                'number' => $this->normalizePhone($number),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function responseErrorMessage(Response $response): string
    {
        $json = $response->json();
        $nested = data_get($json, 'response.message');

        if (is_array($nested)) {
            $nested = implode(' ', array_filter(array_map('strval', $nested)));
        }

        $message = trim((string) (
            $nested
            ?? data_get($json, 'message')
            ?? data_get($json, 'error')
            ?? $response->body()
        ));

        if ($message === '') {
            return 'Evolution rejeitou o envio da mensagem.';
        }

        if (str_contains(strtolower($message), 'does not exist')) {
            return 'Instância não encontrada na Evolution. Verifique EVOLUTION_INSTANCE_NAME.';
        }

        if (str_contains(strtolower($message), 'not connected') || str_contains(strtolower($message), 'disconnected')) {
            return 'WhatsApp desconectado na Evolution. Reconecte o chip.';
        }

        return IntegrationErrorReporter::sanitize($message);
    }

    public function sendTextForStore(Store $store, string $number, string $text): void
    {
        $this->sendText($this->instanceNameForStore($store), $number, $text);
    }

    public function isConnectedState(?string $state): bool
    {
        return in_array(strtolower((string) $state), ['open', 'connected'], true);
    }

    private function client(bool $provision = false, bool $sendMessage = false): PendingRequest
    {
        $timeout = match (true) {
            $provision => (int) config('services.evolution.provision_timeout', 90),
            $sendMessage => (int) config('services.evolution.message_timeout', 45),
            default => (int) config('services.evolution.timeout', 35),
        };

        return Http::baseUrl(config('services.evolution.base_url'))
            ->timeout($timeout)
            ->connectTimeout(min(10, $timeout))
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

        $json = $response->json();
        $nestedMessage = data_get($json, 'response.message');

        if (is_array($nestedMessage)) {
            $nestedMessage = implode(' ', $nestedMessage);
        }

        $haystack = Str::lower(implode(' ', array_filter([
            data_get($json, 'message'),
            data_get($json, 'error'),
            is_string($nestedMessage) ? $nestedMessage : null,
            json_encode($json),
            $response->body(),
        ])));

        return str_contains($haystack, 'already')
            || str_contains($haystack, 'in use')
            || str_contains($haystack, 'exists')
            || str_contains($haystack, 'duplicate');
    }

    public function normalizePhonePublic(string $phone): string
    {
        return $this->normalizePhone($phone);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        while (str_starts_with($digits, '0') && strlen($digits) > 11) {
            $digits = substr($digits, 1);
        }

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            return $digits;
        }

        if (strlen($digits) === 11) {
            return '55'.$digits;
        }

        if (strlen($digits) === 10) {
            return '55'.substr($digits, 0, 2).'9'.substr($digits, 2);
        }

        return $digits;
    }

    private function stateCacheKey(string $instanceName): string
    {
        return 'evolution_state:'.Str::slug($instanceName);
    }

    private function phoneFromWhatsappId(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $jid = explode('@', (string) $value)[0];
        $digits = preg_replace('/\D+/', '', $jid) ?? '';

        return $digits !== '' ? $digits : null;
    }
}
