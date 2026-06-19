<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Store;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PagarMeService
{
    public function isConfigured(): bool
    {
        try {
            return filled(config('services.pagarme.secret_key'))
                && filled(config('services.pagarme.public_key'));
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Erro ao verificar configuração do Pagar.me: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function configurationStatus(): array
    {
        try {
            $missing = array_values(array_filter([
                blank(config('services.pagarme.secret_key')) ? 'PAGARME_SECRET_KEY' : null,
                blank(config('services.pagarme.public_key')) ? 'PAGARME_PUBLIC_KEY' : null,
                blank(config('services.pagarme.webhook_url')) ? 'PAGARME_WEBHOOK_URL' : null,
            ]));

            return [
                'configured' => $this->isConfigured(),
                'environment' => config('services.pagarme.environment', 'sandbox'),
                'base_url' => config('services.pagarme.base_url', 'https://api.pagar.me/core/v5'),
                'account_id' => config('services.pagarme.account_id'),
                'public_key' => config('services.pagarme.public_key'),
                'webhook_url' => config('services.pagarme.webhook_url'),
                'missing' => $missing,
            ];
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Erro ao montar status de configuração do Pagar.me: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function createSubscription(Store $store, Plan $plan, string $cardToken, ?string $billingEmail = null): array
    {
        try {
            $this->validateSubscription($store, $plan, $cardToken, $billingEmail);

            $customer = $this->createOrUpdateCustomer($store, $billingEmail);
            $customerId = data_get($customer, 'id');

            if (blank($customerId)) {
                throw new RuntimeException('Pagar.me não retornou o ID do cliente.');
            }

            $cardId = $this->attachCardToCustomer($customerId, $cardToken);

            $payload = [
                'payment_method' => 'credit_card',
                'currency' => 'BRL',
                'interval' => 'month',
                'interval_count' => 1,
                'billing_type' => 'prepaid',
                'installments' => 1,
                'customer_id' => $customerId,
                'card_id' => $cardId,
                'statement_descriptor' => Str::limit(config('services.pagarme.statement_descriptor', 'PARTIUMENU'), 13, ''),
                'metadata' => [
                    'store_id' => (string) $store->id,
                    'plan_id' => (string) $plan->id,
                    'plan_slug' => (string) $plan->slug,
                    'gateway' => 'pagarme',
                ],
                'items' => [
                    [
                        'description' => "PartiuMenu - {$plan->name}",
                        'quantity' => 1,
                        'pricing_scheme' => [
                            'scheme_type' => 'unit',
                            'price' => $this->amountInCents($plan),
                        ],
                    ],
                ],
            ];

            Log::info('Pagar.me subscription payload', [
                'store_id' => $store->id,
                'plan_id' => $plan->id,
                'customer_id' => $customerId,
                'amount' => data_get($payload, 'items.0.pricing_scheme.price'),
            ]);

            $response = $this->request()
                ->asJson()
                ->post($this->baseUrl() . '/subscriptions', $payload);

            if ($response->failed()) {
                $this->logFailure('Pagar.me subscription request failed', $response, [
                    'store_id' => $store->id,
                    'plan_id' => $plan->id,
                    'customer_id' => $customerId,
                ]);

                throw new RuntimeException($this->formatError($response));
            }

            $subscription = $response->json();

            $this->assertSubscriptionCreated($subscription);

            return [
                ...$subscription,
                'customer_id' => $customerId,
            ];
        } catch (Throwable $e) {
            throw new RuntimeException(
                $e->getMessage() ?: 'Erro ao criar assinatura no Pagar.me.',
                0,
                $e
            );
        }
    }

    public function createOrder(array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Pagar.me não está configurado.');
        }

        $response = $this->request()
            ->asJson()
            ->post($this->baseUrl().'/orders', $payload);

        if ($response->failed()) {
            $this->logFailure('Pagar.me order request failed', $response, [
                'order_id' => data_get($payload, 'metadata.order_id'),
            ]);

            throw new RuntimeException($this->formatError($response));
        }

        return $response->json();
    }

    public function getOrder(string $orderId): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Pagar.me não está configurado.');
        }

        if (blank($orderId)) {
            throw new RuntimeException('ID do pedido Pagar.me não informado.');
        }

        $response = $this->request()
            ->get($this->baseUrl().'/orders/'.$orderId);

        if ($response->failed()) {
            throw new RuntimeException($this->formatError($response));
        }

        return $response->json();
    }

    public function getSubscription(string $subscriptionId): array
    {
        try {
            if (!$this->isConfigured()) {
                throw new RuntimeException('Pagar.me não está configurado.');
            }

            if (blank($subscriptionId)) {
                throw new RuntimeException('ID da assinatura não informado.');
            }

            $response = $this->request()
                ->get($this->baseUrl() . '/subscriptions/' . $subscriptionId);

            if ($response->failed()) {
                throw new RuntimeException($this->formatError($response));
            }

            return $response->json();
        } catch (Throwable $e) {
            throw new RuntimeException(
                $e->getMessage() ?: 'Erro ao consultar assinatura no Pagar.me.',
                0,
                $e
            );
        }
    }

    public function parseReference(array $payload): array
    {
        $metadata = data_get($payload, 'metadata', []);

        return [
            'store_id' => (int) data_get($metadata, 'store_id'),
            'plan_id' => (int) data_get($metadata, 'plan_id'),
        ];
    }

    public function mapSubscriptionStatus(?string $status): string
    {
        return match ($status) {
            'active', 'future', 'trialing', 'paid' => 'active',
            'canceled', 'failed' => 'canceled',
            'past_due', 'overdue' => 'past_due',
            default => 'inactive',
        };
    }

    public function shouldActivatePlan(?string $status): bool
    {
        return in_array($status, ['active', 'future', 'trialing', 'paid'], true);
    }

    public function assertSubscriptionCreated(array $subscription): void
    {
        $status = (string) data_get($subscription, 'status');

        if ($this->shouldActivatePlan($status)) {
            return;
        }

        $reason = data_get($subscription, 'current_cycle.status')
            ?? data_get($subscription, 'gateway_message')
            ?? 'Pagamento recusado pelo emissor ou gateway.';

        throw new RuntimeException(
            sprintf('Assinatura recusada pelo Pagar.me (%s). %s', $status ?: 'unknown', $reason)
        );
    }

    public function validatePlanUpgrade(Store $store, Plan $plan): void
    {
        $currentPlan = $store->plan;

        if (!$currentPlan) {
            return;
        }

        if ((int) $currentPlan->id === (int) $plan->id) {
            throw new RuntimeException('Sua loja já está neste plano.');
        }

        if ((float) $plan->price <= (float) $currentPlan->price) {
            throw new RuntimeException('Não é possível regredir de plano. Entre em contato com o suporte.');
        }
    }

    public function createCardToken(array $card): string
    {
        try {
            if (!$this->isConfigured()) {
                throw new RuntimeException('Pagar.me não está configurado.');
            }

            $expYear = (int) $card['exp_year'];
            if ($expYear < 100) {
                $expYear += 2000;
            }

            $payload = [
                'type' => 'card',
                'card' => [
                    'number' => preg_replace('/\D/', '', (string) $card['number']),
                    'holder_name' => (string) $card['holder_name'],
                    'holder_document' => preg_replace('/\D/', '', (string) $card['holder_document']),
                    'exp_month' => (int) $card['exp_month'],
                    'exp_year' => $expYear,
                    'cvv' => preg_replace('/\D/', '', (string) $card['cvv']),
                ],
            ];

            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('services.pagarme.timeout', 20))
                ->post(
                    $this->baseUrl() . '/tokens?appId=' . urlencode((string) config('services.pagarme.public_key')),
                    $payload
                );

            if ($response->failed()) {
                $this->logFailure('Pagar.me token request failed', $response);

                throw new RuntimeException($this->formatError($response));
            }

            $token = data_get($response->json(), 'id');

            if (blank($token)) {
                throw new RuntimeException('Pagar.me não retornou o token do cartão.');
            }

            return (string) $token;
        } catch (Throwable $e) {
            throw new RuntimeException(
                $e->getMessage() ?: 'Erro ao tokenizar cartão no Pagar.me.',
                0,
                $e
            );
        }
    }

    private function attachCardToCustomer(string $customerId, string $cardToken): string
    {
        $response = $this->request()
            ->asJson()
            ->post($this->baseUrl() . '/customers/' . $customerId . '/cards', [
                'token' => $cardToken,
            ]);

        if ($response->failed()) {
            $this->logFailure('Pagar.me card attach request failed', $response, [
                'customer_id' => $customerId,
            ]);

            throw new RuntimeException($this->formatError($response));
        }

        $cardId = data_get($response->json(), 'id');

        if (blank($cardId)) {
            throw new RuntimeException('Pagar.me não retornou o cartão vinculado ao cliente.');
        }

        return (string) $cardId;
    }

    private function createOrUpdateCustomer(Store $store, ?string $billingEmail): array
    {
        $email = (string) ($billingEmail ?: $store->billing_email ?: $store->user?->email);

        $payload = [
            'name' => $store->user?->name ?: $store->name,
            'email' => $email,
            'code' => 'store_' . $store->id,
            'type' => 'individual',
            'metadata' => [
                'store_id' => (string) $store->id,
                'store_name' => (string) $store->name,
            ],
        ];

        $response = $this->request()
            ->asJson()
            ->post($this->baseUrl() . '/customers', $payload);

        if ($response->failed()) {
            $this->logFailure('Pagar.me customer request failed', $response, [
                'store_id' => $store->id,
                'email' => $email,
            ]);

            throw new RuntimeException($this->formatError($response));
        }

        return $response->json();
    }

    private function request()
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Pagar.me não está configurado.');
        }

        return Http::withBasicAuth(config('services.pagarme.secret_key'), '')
            ->acceptJson()
            ->timeout((int) config('services.pagarme.timeout', 20));
    }

    private function validateSubscription(Store $store, Plan $plan, string $cardToken, ?string $billingEmail): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Pagar.me não está configurado.');
        }

        if (blank($cardToken)) {
            throw new RuntimeException('Token do cartão não informado.');
        }

        $email = (string) ($billingEmail ?: $store->billing_email ?: $store->user?->email);

        if (blank($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail de cobrança válido para criar a assinatura.');
        }

        if ($this->amountInCents($plan) <= 0) {
            throw new RuntimeException('O plano selecionado não possui valor válido para assinatura.');
        }
    }

    private function amountInCents(Plan $plan): int
    {
        return (int) round(((float) $plan->price) * 100);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.pagarme.base_url', 'https://api.pagar.me/core/v5'), '/');
    }

    private function formatError(Response $response): string
    {
        $body = $response->json();

        $message =
            data_get($body, 'message')
            ?? data_get($body, 'errors.0.message')
            ?? data_get($body, 'errors.0')
            ?? $response->body();

        return sprintf('Pagar.me retornou erro %s: %s', $response->status(), $message);
    }

    private function logFailure(string $message, Response $response, array $context = []): void
    {
        Log::warning($message, [
            ...$context,
            'status' => $response->status(),
            'body' => $response->json() ?: $response->body(),
            'request_id' => $response->header('x-request-id'),
        ]);
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signature, ?string $secret = null): bool
    {
        $secret ??= config('services.pagarme.webhook_secret');

        if (blank($secret)) {
            return ! app()->isProduction();
        }

        if (blank($signature)) {
            return false;
        }

        $provided = str_starts_with($signature, 'sha256=')
            ? substr($signature, 7)
            : $signature;

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $provided);
    }

    public function verifyWebhookBasicAuth(?string $username, ?string $password): bool
    {
        $expectedUser = (string) config('services.pagarme.webhook_user', 'partiumenu');
        $expectedPassword = (string) config('services.pagarme.webhook_secret');

        if (blank($expectedPassword)) {
            return ! app()->isProduction();
        }

        if (blank($password)) {
            return false;
        }

        if (filled($expectedUser) && ! hash_equals($expectedUser, (string) $username)) {
            return false;
        }

        return hash_equals($expectedPassword, (string) $password);
    }

    public function verifyWebhookRequest(string $rawBody, ?string $signature, ?string $username, ?string $password): bool
    {
        if ($this->verifyWebhookBasicAuth($username, $password)) {
            return true;
        }

        return $this->verifyWebhookSignature($rawBody, $signature);
    }
}
