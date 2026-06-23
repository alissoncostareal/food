<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Models\Store;
use App\Models\StorePaymentProvider;
use App\Services\OrderPixPaymentService;
use App\Services\Payments\StorePaymentConnectionService;
use App\Services\Payments\StorePixGatewayResolver;
use App\Support\PlatformPaymentProviders;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class MerchantPaymentController extends Controller
{
    use ResolvesMerchantStore;

    public function connection(StorePaymentConnectionService $connections)
    {
        return response()->json($connections->connectionPayload($this->merchantStore()));
    }

    public function updateSettings(Request $request, StorePaymentConnectionService $connections)
    {
        $store = $this->merchantStore();

        $validated = $request->validate([
            'online_payments_enabled' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('online_payments_enabled', $validated)) {
            $enabled = (bool) $validated['online_payments_enabled'];

            if ($enabled && ! $connections->paymentReady($store)) {
                return response()->json([
                    'message' => 'Conecte e ative um gateway Pix antes de habilitar Pix online.',
                ], 422);
            }

            $methods = $store->acceptedPaymentMethods();

            if ($enabled && ! in_array(Store::PAYMENT_PIX_ONLINE, $methods, true)) {
                $methods[] = Store::PAYMENT_PIX_ONLINE;
            }

            if (! $enabled) {
                $methods = array_values(array_diff($methods, [Store::PAYMENT_PIX_ONLINE]));
            }

            if ($methods === []) {
                return response()->json([
                    'message' => 'A loja precisa aceitar pelo menos uma forma de pagamento.',
                ], 422);
            }

            $store->update([
                'online_payments_enabled' => $enabled,
                'accepted_payment_methods' => $methods,
            ]);
        }

        return response()->json([
            'message' => 'Configurações de recebimento salvas.',
            'payments' => $connections->connectionPayload($store->fresh()),
        ]);
    }

    public function saveProvider(
        Request $request,
        string $provider,
        StorePaymentConnectionService $connections,
        StorePixGatewayResolver $resolver
    ) {
        $store = $this->merchantStore();
        $providerConfig = config("payments.providers.{$provider}");

        if (! $providerConfig) {
            return response()->json(['message' => 'Gateway não suportado.'], 404);
        }

        if (! PlatformPaymentProviders::isAvailable($provider, $store)) {
            return response()->json([
                'message' => 'Este gateway está desativado na plataforma. Entre em contato com o suporte.',
            ], 403);
        }

        $validated = $request->validate([
            'connection_method' => ['required', 'string'],
            'credentials' => ['required', 'array'],
            'activate' => ['sometimes', 'boolean'],
        ]);

        $methodKey = $validated['connection_method'];
        $methodConfig = $providerConfig['connection_methods'][$methodKey] ?? null;

        if (! $methodConfig) {
            return response()->json(['message' => 'Método de conexão inválido.'], 422);
        }

        try {
            $credentials = $this->normalizeCredentials($methodConfig['fields'] ?? [], $validated['credentials']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $connection = StorePaymentProvider::query()->firstOrNew([
            'store_id' => $store->id,
            'provider' => $provider,
        ]);

        $connection->fill([
            'connection_method' => $methodKey,
            'credentials' => $credentials,
            'status' => StorePaymentProvider::STATUS_PENDING,
            'last_error' => null,
        ]);

        try {
            $connection->save();
            $resolver->resolve($connection)->testConnection($connection);

            $connection->forceFill([
                'status' => StorePaymentProvider::STATUS_CONNECTED,
                'connected_at' => now(),
                'account_label' => $this->buildAccountLabel($provider, $credentials),
                'last_error' => null,
            ])->save();

            if ($request->boolean('activate', true)) {
                $connections->activateForPix($store, $connection);
            }
        } catch (Throwable $e) {
            $connection->forceFill([
                'status' => StorePaymentProvider::STATUS_ERROR,
                'last_error' => $e->getMessage(),
            ])->save();

            return response()->json([
                'message' => 'Não foi possível validar as credenciais.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 422);
        }

        return response()->json([
            'message' => 'Gateway conectado com sucesso.',
            'payments' => $connections->connectionPayload($store->fresh()),
        ]);
    }

    public function activateProvider(string $provider, StorePaymentConnectionService $connections)
    {
        $store = $this->merchantStore();

        $connection = StorePaymentProvider::query()
            ->where('store_id', $store->id)
            ->where('provider', $provider)
            ->firstOrFail();

        if (! PlatformPaymentProviders::isAvailable($provider, $store)) {
            return response()->json(['message' => 'Este gateway está desativado na plataforma.'], 403);
        }

        try {
            $connections->activateForPix($store, $connection);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Gateway ativado para Pix online.',
            'payments' => $connections->connectionPayload($store->fresh()),
        ]);
    }

    public function disconnectProvider(string $provider, StorePaymentConnectionService $connections)
    {
        $store = $this->merchantStore();

        $connection = StorePaymentProvider::query()
            ->where('store_id', $store->id)
            ->where('provider', $provider)
            ->first();

        if (! $connection) {
            return response()->json(['message' => 'Conexão não encontrada.'], 404);
        }

        if ($connection->is_active_for_pix) {
            $store->forceFill(['payment_pix_provider_id' => null])->save();

            $methods = array_values(array_diff($store->acceptedPaymentMethods(), [Store::PAYMENT_PIX_ONLINE]));

            $store->update([
                'online_payments_enabled' => false,
                'accepted_payment_methods' => $methods !== [] ? $methods : ['pix', 'cash', 'debit_card', 'credit_card'],
            ]);
        }

        $connection->delete();

        return response()->json([
            'message' => 'Gateway desconectado.',
            'payments' => $connections->connectionPayload($store->fresh()),
        ]);
    }

    private function normalizeCredentials(array $fields, array $input): array
    {
        $normalized = [];

        foreach ($fields as $key => $field) {
            $value = trim((string) ($input[$key] ?? ''));

            if (($field['required'] ?? false) && $value === '') {
                throw new \InvalidArgumentException("O campo {$field['label']} é obrigatório.");
            }

            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('Informe as credenciais do gateway.');
        }

        if (! isset($normalized['environment'])) {
            $normalized['environment'] = 'sandbox';
        }

        return $normalized;
    }

    private function buildAccountLabel(string $provider, array $credentials): string
    {
        return match ($provider) {
            'pagarme' => 'Pagar.me · '.Str::mask((string) ($credentials['public_key'] ?? ''), '*', 4),
            'mercadopago' => 'Mercado Pago · token …'.substr((string) ($credentials['access_token'] ?? ''), -4),
            'asaas' => 'Asaas · '.($credentials['environment'] ?? 'sandbox'),
            default => ucfirst($provider),
        };
    }
}
