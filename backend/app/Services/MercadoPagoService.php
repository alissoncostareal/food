<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MercadoPagoService
{
    public function createSubscription(Store $store, Plan $plan): array
    {
        try {
            $this->validateCheckout($store, $plan);

            $payload = [
                'reason' => "PartiuMenu - {$plan->name}",
                'external_reference' => $this->buildExternalReference($store, $plan),
                'payer_email' => $store->user?->email,
                'back_url' => config('services.mercado_pago.success_url') ?: 'http://localhost:5175/billing?payment=success',
                'auto_recurring' => [
                    'frequency' => 1,
                    'frequency_type' => 'months',
                    'transaction_amount' => round((float) $plan->price, 2),
                    'currency_id' => 'BRL',
                ],
            ];

            \Log::info('Mercado Pago subscription payload', [
                'payload' => $payload,
            ]);

            $response = Http::withToken(config('services.mercado_pago.access_token'))
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->post($this->baseUrl() . '/preapproval', $payload);

            if ($response->failed()) {
                throw new RuntimeException($this->formatMercadoPagoError($response->json()));
            }

            return $response->json();
        } catch (Throwable $e) {
            throw new RuntimeException(
                $e->getMessage() ?: 'Erro ao criar assinatura no Mercado Pago.',
                0,
                $e
            );
        }
    }
    public function isConfigured(): bool
    {
        try {
            return filled(config('services.mercado_pago.access_token'))
                && filled(config('services.mercado_pago.public_key'));
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Erro ao verificar configuração do Mercado Pago: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function configurationStatus(): array
    {
        try {
            $missing = array_values(array_filter([
                blank(config('services.mercado_pago.access_token')) ? 'MERCADO_PAGO_ACCESS_TOKEN' : null,
                blank(config('services.mercado_pago.public_key')) ? 'MERCADO_PAGO_PUBLIC_KEY' : null,
                blank(config('services.mercado_pago.success_url')) ? 'MERCADO_PAGO_SUCCESS_URL' : null,
                blank(config('services.mercado_pago.failure_url')) ? 'MERCADO_PAGO_FAILURE_URL' : null,
                blank(config('services.mercado_pago.pending_url')) ? 'MERCADO_PAGO_PENDING_URL' : null,
            ]));

            return [
                'configured' => $this->isConfigured(),
                'environment' => config('services.mercado_pago.environment', 'sandbox'),
                'base_url' => config('services.mercado_pago.base_url', 'https://api.mercadopago.com'),
                'webhook_url' => config('services.mercado_pago.webhook_url'),
                'success_url' => config('services.mercado_pago.success_url'),
                'failure_url' => config('services.mercado_pago.failure_url'),
                'pending_url' => config('services.mercado_pago.pending_url'),
                'missing' => $missing,
            ];
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Erro ao montar status de configuração do Mercado Pago: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function createCheckoutPreference(Store $store, Plan $plan): array
    {
        try {
            $this->validateCheckout($store, $plan);

            $payload = $this->buildPreferencePayload($store, $plan);
            \Log::info('Mercado Pago preference payload', [
                'notification_url' => $payload['notification_url'] ?? null,
                'external_reference' => $payload['external_reference'] ?? null,
                'payload' => $payload,
            ]);

            $response = Http::withToken(config('services.mercado_pago.access_token'))
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->post($this->baseUrl() . '/checkout/preferences', $payload);

            if ($response->failed()) {
                throw new RuntimeException($this->formatMercadoPagoError($response->json()));
            }

            $preference = $response->json();

            return [
                'id' => data_get($preference, 'id'),
                'init_point' => data_get($preference, 'init_point'),
                'sandbox_init_point' => data_get($preference, 'sandbox_init_point'),
                'external_reference' => data_get($preference, 'external_reference'),
                'environment' => config('services.mercado_pago.environment', 'sandbox'),
                'raw' => $preference,
            ];
        } catch (Throwable $e) {
            throw new RuntimeException(
                $e->getMessage() ?: 'Erro ao criar checkout no Mercado Pago.',
                0,
                $e
            );
        }
    }
    public function getPayment(string|int $paymentId): array
    {
        try {
            if (!$this->isConfigured()) {
                throw new RuntimeException('Mercado Pago não está configurado.');
            }

            $response = Http::withToken(config('services.mercado_pago.access_token'))
                ->acceptJson()
                ->timeout(20)
                ->get($this->baseUrl() . '/v1/payments/' . $paymentId);

            if ($response->failed()) {
                throw new RuntimeException($this->formatMercadoPagoError($response->json()));
            }

            return $response->json();
        } catch (Throwable $e) {
            throw new RuntimeException(
                $e->getMessage() ?: 'Erro ao consultar pagamento no Mercado Pago.',
                0,
                $e
            );
        }
    }

    public function parseExternalReference(?string $externalReference): array
    {
        try {
            if (blank($externalReference)) {
                throw new RuntimeException('Referência externa não informada no pagamento.');
            }

            $parts = explode(':', $externalReference);

            if (count($parts) < 6) {
                throw new RuntimeException('Referência externa inválida.');
            }

            return [
                'app' => $parts[0] ?? null,
                'type' => $parts[1] ?? null,
                'store_id' => (int) ($parts[2] ?? 0),
                'plan_label' => $parts[3] ?? null,
                'plan_id' => (int) ($parts[4] ?? 0),
                'uuid' => $parts[5] ?? null,
            ];
        } catch (Throwable $e) {
            throw new RuntimeException(
                $e->getMessage() ?: 'Erro ao interpretar referência externa.',
                0,
                $e
            );
        }
    }

    private function validateCheckout(Store $store, Plan $plan): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Mercado Pago não está configurado.');
        }

        if (!$plan->is_active) {
            throw new RuntimeException('Este plano não está disponível para assinatura.');
        }

        $currentPrice = (float) ($store->plan?->price ?? 0);
        $newPrice = (float) $plan->price;

        if ($store->plan_id && (int) $store->plan_id === (int) $plan->id) {
            throw new RuntimeException('Este já é o plano atual da loja.');
        }

        if ($store->plan_id && $newPrice <= $currentPrice) {
            throw new RuntimeException('Downgrade ou troca lateral deve ser feita pelo suporte.');
        }

        if ($newPrice <= 0) {
            throw new RuntimeException('O plano selecionado não possui valor válido para checkout.');
        }
    }

    private function buildPreferencePayload(Store $store, Plan $plan): array
    {
        $newPrice = (float) $plan->price;

        $payload = [
            'items' => [
                [
                    'id' => (string) $plan->id,
                    'title' => "PartiuMenu - {$plan->name}",
                    'description' => $plan->description ?: "Assinatura mensal do plano {$plan->name}",
                    'quantity' => 1,
                    'currency_id' => 'BRL',
                    'unit_price' => round((float) $plan->price, 2),
                ],
            ],
            'external_reference' => $this->buildExternalReference($store, $plan),
            'notification_url' => config('services.mercado_pago.webhook_url'),
            'metadata' => [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'environment' => config('services.mercado_pago.environment', 'sandbox'),
            ],
        ];

        $webhookUrl = config('services.mercado_pago.webhook_url');

        if (filled($webhookUrl)) {
            $payload['notification_url'] = $webhookUrl;
        }

        return $payload;
    }

    private function formatMercadoPagoError(?array $error): string
    {
        if (!$error) {
            return 'Erro ao criar checkout no Mercado Pago.';
        }

        $message = data_get($error, 'message')
            ?: data_get($error, 'error')
            ?: 'Erro ao criar checkout no Mercado Pago.';

        $cause = data_get($error, 'cause');

        if (is_array($cause) && count($cause) > 0) {
            $causeMessages = collect($cause)
                ->map(function ($item) {
                    if (is_array($item)) {
                        return data_get($item, 'description')
                            ?: data_get($item, 'message')
                            ?: data_get($item, 'code');
                    }

                    return $item;
                })
                ->filter()
                ->values()
                ->implode(' | ');

            if ($causeMessages) {
                return "{$message}: {$causeMessages}";
            }
        }

        $encoded = json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded ? "{$message}: {$encoded}" : $message;
    }

    private function baseUrl(): string
    {
        return rtrim(config('services.mercado_pago.base_url', 'https://api.mercadopago.com'), '/');
    }

    private function buildExternalReference(Store $store, Plan $plan): string
    {
        return implode(':', [
            'partiumenu',
            'store',
            $store->id,
            'plan',
            $plan->id,
            Str::uuid()->toString(),
        ]);
    }
}
