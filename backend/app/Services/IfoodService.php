<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class IfoodService
{
    public const WEBHOOK_PATH = '/api/v1/integrations/ifood/webhook';

    public function configurationStatus(): array
    {
        try {
            $missing = array_values(array_filter([
                blank(config('services.ifood.distributed_client_id')) ? 'IFOOD_DISTRIBUTED_CLIENT_ID' : null,
                blank(config('services.ifood.distributed_client_secret')) ? 'IFOOD_DISTRIBUTED_CLIENT_SECRET' : null,
                $this->isProduction() && blank(config('services.ifood.webhook_secret')) ? 'IFOOD_WEBHOOK_SECRET' : null,
            ]));

            $webhookUrl = $this->resolveWebhookUrl();

            return [
                'configured' => $this->isDistributedConfigured(),
                'distributed_configured' => $this->isDistributedConfigured(),
                'environment' => config('services.ifood.environment', 'sandbox'),
                'base_url' => config('services.ifood.base_url'),
                'auth_url' => $this->authUrl(),
                'webhook_path' => self::WEBHOOK_PATH,
                'webhook_url' => $webhookUrl,
                'webhook_ready' => $this->isWebhookRegisterable($webhookUrl),
                'order_categories' => config('services.ifood.order_categories', []),
                'missing' => $missing,
            ];
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Erro ao montar status de configuração do iFood: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function isDistributedConfigured(): bool
    {
        return filled(config('services.ifood.distributed_client_id'))
            && filled(config('services.ifood.distributed_client_secret'));
    }

    public function resolveWebhookUrl(): ?string
    {
        $explicit = trim((string) config('services.ifood.webhook_url'));

        if ($explicit !== '') {
            return rtrim($explicit, '/');
        }

        $appUrl = rtrim((string) config('app.url'), '/');

        if ($appUrl === '' || $this->isLocalUrl($appUrl)) {
            return null;
        }

        return $appUrl . self::WEBHOOK_PATH;
    }

    public function isWebhookRegisterable(?string $webhookUrl = null): bool
    {
        $webhookUrl ??= $this->resolveWebhookUrl();

        if (blank($webhookUrl)) {
            return false;
        }

        return str_starts_with(strtolower($webhookUrl), 'https://');
    }

    public function validateWebhookSignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('services.ifood.webhook_secret');

        if ($secret === '') {
            if ($this->isProduction()) {
                Log::warning('iFood webhook rejeitado: IFOOD_WEBHOOK_SECRET não configurado em produção');

                return false;
            }

            $secret = (string) (
                config('services.ifood.distributed_client_secret')
                ?: config('services.ifood.centralized_client_secret')
            );
        }

        if ($secret === '' || blank($signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals(strtolower($expected), strtolower(trim($signature)));
    }

    public function isProduction(): bool
    {
        return config('services.ifood.environment', 'sandbox') === 'production';
    }

    public function fetchOrderDetails(Store $store, string $orderId): array
    {
        $token = $this->accessTokenForStore($store);
        $baseUrl = rtrim(config('services.ifood.base_url', 'https://merchant-api.ifood.com.br'), '/');

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->get("{$baseUrl}/order/v1.0/orders/{$orderId}");

        if ($response->failed()) {
            $this->logFailure('iFood order details failed', $response);

            throw new RuntimeException($this->formatIfoodError($response->json()) ?: 'Não foi possível buscar detalhes do pedido iFood.');
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    public function usesPresenceByMerchant(): bool
    {
        return (bool) config('services.ifood.presence_by_merchant', false);
    }

    public function storeConnectionStatus(Store $store): array
    {
        $platform = $this->configurationStatus();

        return [
            'platform' => [
                'configured' => $platform['configured'],
                'distributed_configured' => $platform['distributed_configured'],
                'environment' => $platform['environment'],
                'webhook_ready' => $platform['webhook_ready'],
            ],
            'store' => $store->ifoodConnectionPayload(),
        ];
    }

    public function isSandbox(): bool
    {
        return config('services.ifood.environment', 'sandbox') === 'sandbox';
    }

    public function listCentralizedSandboxMerchants(): array
    {
        if (! $this->isSandbox() || ! $this->isCentralizedConfigured()) {
            return [];
        }

        $token = $this->requestCentralizedToken();
        $merchants = $this->fetchMerchantList($token);

        return array_map(static function (array $merchant): array {
            return [
                'id' => data_get($merchant, 'id'),
                'name' => data_get($merchant, 'name') ?: data_get($merchant, 'corporateName'),
                'source' => 'sandbox_centralized',
            ];
        }, $merchants);
    }

    public function merchantAllowedInSandbox(string $merchantId): bool
    {
        if (! $this->isSandbox()) {
            return false;
        }

        return collect($this->listCentralizedSandboxMerchants())
            ->pluck('id')
            ->contains($merchantId);
    }

    public function isCentralizedConfigured(): bool
    {
        return filled(config('services.ifood.centralized_client_id'))
            && filled(config('services.ifood.centralized_client_secret'));
    }

    public function createUserCode(Store $store): array
    {
        if (! $this->isDistributedConfigured()) {
            throw new RuntimeException('App distribuído iFood não configurado no PartiuMenu.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->post($this->userCodeUrl(), [
                'clientId' => config('services.ifood.distributed_client_id'),
                'clientSecret' => config('services.ifood.distributed_client_secret'),
            ]);

        if ($response->failed()) {
            $this->logFailure('iFood userCode request failed', $response);

            throw new RuntimeException($this->formatIfoodError($response->json()));
        }

        $payload = $response->json();

        $store->fill([
            'ifood_authorization_code_verifier' => data_get($payload, 'authorizationCodeVerifier'),
            'ifood_integration_status' => 'pending',
            'ifood_last_error' => null,
        ])->save();

        return [
            'user_code' => data_get($payload, 'userCode'),
            'verification_url' => data_get($payload, 'verificationUrl'),
            'verification_url_complete' => data_get($payload, 'verificationUrlComplete'),
            'expires_in' => data_get($payload, 'expiresIn'),
        ];
    }

    public function exchangeAuthorizationCode(Store $store, string $authorizationCode): Store
    {
        if (blank($store->ifood_authorization_code_verifier)) {
            throw new RuntimeException('Gere um novo código de autorização antes de continuar.');
        }

        $authorizationCode = strtoupper(trim($authorizationCode));

        $response = Http::asForm()
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->post($this->authUrl(), [
                'grantType' => 'authorization_code',
                'clientId' => config('services.ifood.distributed_client_id'),
                'clientSecret' => config('services.ifood.distributed_client_secret'),
                'authorizationCode' => $authorizationCode,
                'authorizationCodeVerifier' => $store->ifood_authorization_code_verifier,
            ]);

        if ($response->failed()) {
            $this->logFailure('iFood authorization_code exchange failed', $response);

            throw new RuntimeException($this->formatIfoodError($response->json()));
        }

        $this->persistTokenResponse($store, $response->json());
        $store->fill([
            'ifood_authorization_code_verifier' => null,
            'ifood_integration_status' => 'pending',
            'ifood_last_error' => null,
        ])->save();

        return $store->fresh();
    }

    public function listAuthorizedMerchants(Store $store): array
    {
        $token = $this->accessTokenForStore($store);
        $merchants = $this->fetchMerchantList($token);

        return array_map(static function (array $merchant): array {
            return [
                'id' => data_get($merchant, 'id'),
                'name' => data_get($merchant, 'name') ?: data_get($merchant, 'corporateName'),
            ];
        }, $merchants);
    }

    public function saveStoreMerchantId(Store $store, string $merchantId): Store
    {
        $merchantId = trim($merchantId);

        if (! $this->isValidMerchantId($merchantId)) {
            throw new RuntimeException('Informe um Merchant ID válido (UUID) do portal iFood.');
        }

        $store->fill([
            'ifood_merchant_id' => $merchantId,
            'ifood_integration_status' => filled($store->ifood_access_token) ? 'pending' : 'disconnected',
            'ifood_last_error' => null,
        ])->save();

        return $store->fresh();
    }

    public function testStoreConnection(Store $store): array
    {
        if (blank($store->ifood_merchant_id)) {
            throw new RuntimeException('Informe o Merchant ID da sua loja no iFood antes de testar.');
        }

        if ($this->canUseSandboxCentralized($store)) {
            $token = $this->requestCentralizedToken();
            $merchant = $this->fetchMerchant($token, $store->ifood_merchant_id);

            $store->fill([
                'ifood_integration_status' => 'connected',
                'ifood_last_error' => null,
                'ifood_connected_at' => now(),
            ])->save();

            return [
                'ok' => true,
                'merchant_id' => $store->ifood_merchant_id,
                'merchant_name' => data_get($merchant, 'name') ?: data_get($merchant, 'corporateName'),
                'status' => $store->ifood_integration_status,
                'mode' => 'sandbox_centralized',
            ];
        }

        if (! filled($store->ifood_access_token)) {
            throw new RuntimeException(
                'Autorize o PartiuMenu no portal iFood antes de testar. Clique em "Gerar código de autorização" e conclua o passo 2.'
            );
        }

        $token = $this->accessTokenForStore($store);
        $authorizedIds = collect($this->fetchMerchantList($token))
            ->map(fn (array $merchant) => (string) data_get($merchant, 'id'))
            ->filter()
            ->values();

        if (! $authorizedIds->contains($store->ifood_merchant_id)) {
            throw new RuntimeException(
                'Este Merchant ID não está entre as lojas que você autorizou no iFood. '
                . 'Use um ID da lista abaixo ou autorize novamente no portal.'
            );
        }

        $merchant = $this->fetchMerchant($token, $store->ifood_merchant_id);

        $store->fill([
            'ifood_integration_status' => 'connected',
            'ifood_last_error' => null,
            'ifood_connected_at' => now(),
        ])->save();

        return [
            'ok' => true,
            'merchant_id' => $store->ifood_merchant_id,
            'merchant_name' => data_get($merchant, 'name') ?: data_get($merchant, 'corporateName'),
            'status' => $store->ifood_integration_status,
            'mode' => 'distributed',
        ];
    }

    public function accessTokenForStore(Store $store): string
    {
        if ($this->canUseSandboxCentralized($store)) {
            return $this->requestCentralizedToken();
        }

        if (blank($store->ifood_access_token)) {
            throw new RuntimeException('Loja ainda não autorizou o PartiuMenu no iFood.');
        }

        if (
            filled($store->ifood_token_expires_at)
            && now()->gte($store->ifood_token_expires_at->copy()->subMinutes(5))
        ) {
            $this->refreshStoreToken($store);
            $store->refresh();
        }

        return (string) $store->ifood_access_token;
    }

    public function disconnectStore(Store $store): Store
    {
        $store->fill([
            'ifood_merchant_id' => null,
            'ifood_access_token' => null,
            'ifood_refresh_token' => null,
            'ifood_authorization_code_verifier' => null,
            'ifood_token_expires_at' => null,
            'ifood_integration_status' => 'disconnected',
            'ifood_last_error' => null,
            'ifood_connected_at' => null,
        ])->save();

        return $store->fresh();
    }

    public function findStoreByMerchantId(?string $merchantId): ?Store
    {
        $merchantId = trim((string) $merchantId);

        if ($merchantId === '') {
            return null;
        }

        return Store::query()
            ->where('ifood_merchant_id', $merchantId)
            ->whereIn('ifood_integration_status', ['connected', 'pending'])
            ->first();
    }

    public function testCentralizedCredentials(): array
    {
        if (! filled(config('services.ifood.centralized_client_id'))) {
            throw new RuntimeException('Credenciais centralizadas do iFood não configuradas.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->post($this->authUrl(), [
                'grantType' => 'client_credentials',
                'clientId' => config('services.ifood.centralized_client_id'),
                'clientSecret' => config('services.ifood.centralized_client_secret'),
            ]);

        if ($response->failed()) {
            $this->logFailure('iFood token request failed', $response);

            throw new RuntimeException($this->formatIfoodError($response->json()));
        }

        $token = $response->json();

        return [
            'ok' => true,
            'token_type' => data_get($token, 'type') ?: data_get($token, 'tokenType'),
            'expires_in' => data_get($token, 'expiresIn') ?: data_get($token, 'expires_in'),
            'has_access_token' => filled(data_get($token, 'accessToken') ?: data_get($token, 'access_token')),
        ];
    }

    private function canUseSandboxCentralized(Store $store): bool
    {
        return $this->isSandbox()
            && filled($store->ifood_merchant_id)
            && $this->merchantAllowedInSandbox($store->ifood_merchant_id);
    }

    private function requestCentralizedToken(): string
    {
        if (! $this->isCentralizedConfigured()) {
            throw new RuntimeException('Credenciais centralizadas do iFood não configuradas.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->post($this->authUrl(), [
                'grantType' => 'client_credentials',
                'clientId' => config('services.ifood.centralized_client_id'),
                'clientSecret' => config('services.ifood.centralized_client_secret'),
            ]);

        if ($response->failed()) {
            $this->logFailure('iFood centralized token request failed', $response);

            throw new RuntimeException($this->formatIfoodError($response->json()));
        }

        $token = data_get($response->json(), 'accessToken')
            ?: data_get($response->json(), 'access_token');

        if (blank($token)) {
            throw new RuntimeException('Token iFood não retornado.');
        }

        return (string) $token;
    }

    private function refreshStoreToken(Store $store): void
    {
        if (blank($store->ifood_refresh_token)) {
            throw new RuntimeException('Token iFood expirado. Autorize novamente no portal iFood.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->post($this->authUrl(), [
                'grantType' => 'refresh_token',
                'clientId' => config('services.ifood.distributed_client_id'),
                'clientSecret' => config('services.ifood.distributed_client_secret'),
                'refreshToken' => $store->ifood_refresh_token,
            ]);

        if ($response->failed()) {
            $this->logFailure('iFood refresh token failed', $response);

            throw new RuntimeException($this->formatIfoodError($response->json()));
        }

        $this->persistTokenResponse($store, $response->json());
    }

    private function persistTokenResponse(Store $store, ?array $payload): void
    {
        $accessToken = data_get($payload, 'accessToken') ?: data_get($payload, 'access_token');
        $refreshToken = data_get($payload, 'refreshToken') ?: data_get($payload, 'refresh_token');
        $expiresIn = (int) (data_get($payload, 'expiresIn') ?: data_get($payload, 'expires_in') ?: 21600);

        if (blank($accessToken)) {
            throw new RuntimeException('Token iFood não retornado.');
        }

        $store->fill([
            'ifood_access_token' => (string) $accessToken,
            'ifood_refresh_token' => filled($refreshToken) ? (string) $refreshToken : $store->ifood_refresh_token,
            'ifood_token_expires_at' => now()->addSeconds(max(60, $expiresIn)),
        ])->save();
    }

    private function fetchMerchantList(string $accessToken): array
    {
        $baseUrl = rtrim(config('services.ifood.base_url', 'https://merchant-api.ifood.com.br'), '/');
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->get("{$baseUrl}/merchant/v1.0/merchants");

        if ($response->failed()) {
            $this->logFailure('iFood merchant list failed', $response);

            throw new RuntimeException($this->formatIfoodError($response->json()));
        }

        $merchants = $response->json();

        return is_array($merchants) ? $merchants : [];
    }

    private function isValidMerchantId(string $merchantId): bool
    {
        return Str::isUuid($merchantId);
    }

    private function fetchMerchant(string $accessToken, string $merchantId): array
    {
        $baseUrl = rtrim(config('services.ifood.base_url', 'https://merchant-api.ifood.com.br'), '/');
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->get("{$baseUrl}/merchant/v1.0/merchants/{$merchantId}");

        if ($response->failed()) {
            $this->logFailure('iFood merchant lookup failed', $response);

            throw new RuntimeException($this->formatMerchantLookupError($response->status(), $response->json()));
        }

        $merchant = $response->json();

        return is_array($merchant) ? $merchant : [];
    }

    private function isLocalUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return true;
        }

        $host = strtolower($host);

        return in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', 'api', 'backend'], true)
            || str_ends_with($host, '.local');
    }

    private function authUrl(): string
    {
        $baseUrl = rtrim(config('services.ifood.base_url', 'https://merchant-api.ifood.com.br'), '/');
        $path = '/' . ltrim(config('services.ifood.auth_path', '/authentication/v1.0/oauth/token'), '/');

        return $baseUrl . $path;
    }

    private function userCodeUrl(): string
    {
        $baseUrl = rtrim(config('services.ifood.base_url', 'https://merchant-api.ifood.com.br'), '/');

        return "{$baseUrl}/authentication/v1.0/oauth/userCode";
    }

    private function logFailure(string $message, $response): void
    {
        Log::warning($message, [
            'status' => $response->status(),
            'body' => $response->json(),
            'headers' => [
                'x-request-id' => $response->header('x-request-id'),
                'x-correlation-id' => $response->header('x-correlation-id'),
            ],
        ]);
    }

    private function formatIfoodError(?array $error): string
    {
        if (! is_array($error)) {
            return 'Erro na comunicação com o iFood.';
        }

        $message = data_get($error, 'message')
            ?: data_get($error, 'error.message')
            ?: data_get($error, 'error_description')
            ?: (is_string(data_get($error, 'error')) ? data_get($error, 'error') : null)
            ?: 'Erro na comunicação com o iFood.';

        if (is_array($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ?: 'Erro na comunicação com o iFood.';
        }

        $code = data_get($error, 'code') ?: data_get($error, 'error.code');

        if (filled($code) && ! str_contains((string) $message, (string) $code)) {
            return trim("{$message} ({$code})");
        }

        return (string) $message;
    }

    private function formatMerchantLookupError(int $status, ?array $error): string
    {
        $message = $this->formatIfoodError($error);

        if ($status === 404) {
            return 'Merchant ID não encontrado no iFood. Verifique se o UUID está correto.';
        }

        return $message;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pollEvents(Store $store): array
    {
        $token = $this->accessTokenForStore($store);
        $baseUrl = rtrim(config('services.ifood.base_url', 'https://merchant-api.ifood.com.br'), '/');

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->get("{$baseUrl}/order/v1.0/events:polling");

        if ($response->failed()) {
            throw new RuntimeException($this->formatIfoodError($response->json()) ?: 'Não foi possível consultar eventos iFood.');
        }

        $events = $response->json();

        return is_array($events) ? $events : [];
    }

    public function acknowledgeEvents(Store $store, array $eventIds): void
    {
        $eventIds = array_values(array_filter($eventIds));

        if ($eventIds === []) {
            return;
        }

        $token = $this->accessTokenForStore($store);
        $baseUrl = rtrim(config('services.ifood.base_url', 'https://merchant-api.ifood.com.br'), '/');

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('services.ifood.timeout', 20))
            ->post("{$baseUrl}/order/v1.0/events/acknowledgment", collect($eventIds)
                ->map(fn (string $id) => ['id' => $id])
                ->values()
                ->all());

        if ($response->failed()) {
            throw new RuntimeException($this->formatIfoodError($response->json()) ?: 'Não foi possível confirmar eventos iFood.');
        }
    }
}
