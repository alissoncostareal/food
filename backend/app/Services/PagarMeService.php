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

    public function createSubscription(
        Store $store,
        Plan $plan,
        string $cardToken,
        ?string $billingEmail = null,
        ?string $holderDocument = null,
        ?string $holderName = null,
        ?string $holderPhone = null,
        ?array $billing = null
    ): array {
        try {
            $billingAddress = $this->buildBillingAddressPayload($billing ?? []);
            $this->validateSubscription($store, $plan, $cardToken, $billingEmail, $holderPhone, $billingAddress);

            $customer = $this->createOrUpdateCustomer(
                $store,
                $billingEmail,
                $holderDocument,
                $holderName,
                $holderPhone,
                $billingAddress
            );
            $customerId = data_get($customer, 'id');

            if (blank($customerId)) {
                throw new RuntimeException('Pagar.me não retornou o ID do cliente.');
            }

            $cardId = $this->attachCardToCustomer($customerId, $cardToken, $billingAddress);

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
                ->withHeaders([
                    'Idempotency-Key' => sprintf('store-%d-plan-%d-%s', $store->id, $plan->id, now()->format('YmdHi')),
                ])
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
            $subscriptionId = (string) data_get($subscription, 'id');

            if ($subscriptionId !== '') {
                try {
                    $refreshed = $this->getSubscription($subscriptionId);

                    if ($refreshed !== []) {
                        $subscription = $refreshed;
                    }
                } catch (Throwable) {
                    // Mantém a resposta inicial se a consulta imediata falhar.
                }
            }

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
        if ($this->subscriptionIsActivable($subscription)) {
            return;
        }

        $status = strtolower((string) data_get($subscription, 'status'));

        Log::warning('Pagar.me subscription rejected after create', [
            'subscription_id' => data_get($subscription, 'id'),
            'status' => $status,
            'status_reason' => data_get($subscription, 'status_reason'),
            'cycle_status' => data_get($subscription, 'current_cycle.status'),
            'charge_status' => data_get($subscription, 'current_cycle.charges.0.status'),
            'acquirer_message' => data_get($subscription, 'current_cycle.charges.0.last_transaction.acquirer_message'),
        ]);

        throw new RuntimeException(
            sprintf(
                'Assinatura recusada pelo Pagar.me (%s). %s',
                $status ?: 'unknown',
                $this->subscriptionFailureReason($subscription)
            )
        );
    }

    public function subscriptionIsActivable(array $subscription): bool
    {
        $status = strtolower((string) data_get($subscription, 'status'));

        if ($this->shouldActivatePlan($status)) {
            return true;
        }

        return $this->subscriptionChargeSucceeded($subscription);
    }

    private function subscriptionChargeSucceeded(array $subscription): bool
    {
        $charges = collect(data_get($subscription, 'current_cycle.charges', []))
            ->merge(data_get($subscription, 'charges', []));

        foreach ($charges as $charge) {
            $chargeStatus = strtolower((string) data_get($charge, 'status', ''));
            $transactionStatus = strtolower((string) data_get($charge, 'last_transaction.status', ''));

            if (in_array($chargeStatus, ['paid', 'captured'], true)) {
                return true;
            }

            if (in_array($transactionStatus, ['paid', 'captured', 'authorized'], true)) {
                return true;
            }
        }

        return false;
    }

    private function subscriptionFailureReason(array $subscription): string
    {
        $candidates = [
            data_get($subscription, 'current_cycle.charges.0.last_transaction.acquirer_message'),
            data_get($subscription, 'current_cycle.charges.0.last_transaction.gateway_response.errors.0.message'),
            data_get($subscription, 'current_cycle.charges.0.last_transaction.gateway_response.code'),
            data_get($subscription, 'status_reason'),
            data_get($subscription, 'gateway_message'),
        ];

        foreach ($candidates as $candidate) {
            $message = trim((string) $candidate);

            if ($message === '' || strtolower($message) === 'billed') {
                continue;
            }

            return $this->translateGatewayMessage($message);
        }

        $chargeStatus = strtolower((string) data_get($subscription, 'current_cycle.charges.0.status', ''));

        if (in_array($chargeStatus, ['failed', 'canceled', 'chargedback'], true)) {
            return 'Cartão recusado na cobrança inicial. Verifique limite, CPF do titular e dados do cartão.';
        }

        return 'Pagamento recusado pelo emissor ou gateway. Verifique cartão, CPF e se as chaves Pagar.me estão no ambiente correto (teste x produção).';
    }

    public function validatePlanUpgrade(Store $store, Plan $plan): void
    {
        if (! $store->hasActiveSubscription()) {
            return;
        }

        $currentPlan = $store->plan;

        if (! $currentPlan) {
            return;
        }

        if ((int) $currentPlan->id === (int) $plan->id) {
            if ($store->hasActiveSubscription() && filled($store->pagarme_subscription_id)) {
                throw new RuntimeException('Sua loja já está neste plano.');
            }

            return;
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

            if ($this->hasBillingInput($card)) {
                $payload['card']['billing_address'] = $this->buildBillingAddressPayload($card);
            }

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

    private function attachCardToCustomer(string $customerId, string $cardToken, array $billingAddress): string
    {
        $response = $this->request()
            ->asJson()
            ->post($this->baseUrl() . '/customers/' . $customerId . '/cards', [
                'token' => $cardToken,
                'billing_address' => $billingAddress,
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

    private function createOrUpdateCustomer(
        Store $store,
        ?string $billingEmail,
        ?string $holderDocument = null,
        ?string $holderName = null,
        ?string $holderPhone = null,
        ?array $billingAddress = null
    ): array {
        $email = (string) ($billingEmail ?: $store->billing_email ?: $store->user?->email);
        $document = preg_replace('/\D+/', '', (string) $holderDocument) ?: null;
        $name = trim((string) ($holderName ?: $store->user?->name ?: $store->name));

        if (blank($document)) {
            throw new RuntimeException('Informe o CPF do titular para criar a assinatura.');
        }

        $payload = [
            'name' => $name !== '' ? $name : 'Titular do cartão',
            'email' => $email,
            'code' => 'store_'.$store->id,
            'type' => 'individual',
            'document' => $document,
            'document_type' => strlen($document) === 14 ? 'CNPJ' : 'CPF',
            'metadata' => [
                'store_id' => (string) $store->id,
                'store_name' => (string) $store->name,
            ],
        ];

        $phones = $this->buildPhonesPayload(
            $holderPhone ?: $store->user?->phone ?: $store->whatsapp_number
        );

        if (! $phones) {
            throw new RuntimeException('Informe o WhatsApp do titular. O Pagar.me exige telefone para assinaturas.');
        }

        $payload['phones'] = $phones;

        if (filled($billingAddress)) {
            $payload['address'] = $billingAddress;
        }

        if (filled($store->pagarme_customer_id)) {
            $existing = $this->getCustomer((string) $store->pagarme_customer_id);

            if ($existing) {
                $response = $this->request()
                    ->asJson()
                    ->put($this->baseUrl().'/customers/'.$store->pagarme_customer_id, $payload);

                if ($response->successful()) {
                    return $response->json();
                }

                $this->logFailure('Pagar.me customer update failed', $response, [
                    'store_id' => $store->id,
                    'customer_id' => $store->pagarme_customer_id,
                ]);
            }
        }

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

    private function getCustomer(string $customerId): ?array
    {
        if (blank($customerId)) {
            return null;
        }

        $response = $this->request()
            ->get($this->baseUrl().'/customers/'.$customerId);

        if ($response->failed()) {
            return null;
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

    private function validateSubscription(
        Store $store,
        Plan $plan,
        string $cardToken,
        ?string $billingEmail,
        ?string $holderPhone = null,
        ?array $billingAddress = null
    ): void {
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

        if (! $this->buildPhonesPayload($holderPhone ?: $store->user?->phone ?: $store->whatsapp_number)) {
            throw new RuntimeException('Informe o WhatsApp do titular. O Pagar.me exige telefone para assinaturas.');
        }

        if (blank($billingAddress)) {
            throw new RuntimeException('Informe o endereço de cobrança completo (CEP, rua, número, cidade e UF).');
        }
    }

    private function hasBillingInput(array $input): bool
    {
        return filled($input['billing_zip_code'] ?? $input['zip_code'] ?? null)
            || filled($input['billing_street'] ?? $input['street'] ?? null);
    }

    public function buildBillingAddressPayload(array $input): array
    {
        $zip = preg_replace('/\D+/', '', (string) ($input['billing_zip_code'] ?? $input['zip_code'] ?? ''));
        $state = strtoupper(substr(trim((string) ($input['billing_state'] ?? $input['state'] ?? '')), 0, 2));
        $city = trim((string) ($input['billing_city'] ?? $input['city'] ?? ''));
        $street = trim((string) ($input['billing_street'] ?? $input['street'] ?? ''));
        $number = trim((string) ($input['billing_number'] ?? $input['number'] ?? ''));
        $district = trim((string) ($input['billing_district'] ?? $input['district'] ?? ''));
        $complement = trim((string) ($input['billing_complement'] ?? $input['complement'] ?? ''));

        if (strlen($zip) !== 8 || $state === '' || $city === '' || $street === '' || $number === '') {
            throw new RuntimeException('Informe CEP, rua, número, cidade e UF para o endereço de cobrança.');
        }

        $line1Parts = array_filter([$number, $street, $district ?: null]);

        return array_filter([
            'country' => 'BR',
            'state' => $state,
            'city' => $city,
            'zip_code' => $zip,
            'line_1' => implode(', ', $line1Parts),
            'line_2' => $complement !== '' ? $complement : null,
        ]);
    }

    private function translateGatewayMessage(string $message): string
    {
        if (str_contains(strtolower($message), 'at least one customer phone is required')) {
            return 'Informe o WhatsApp do titular. O Pagar.me exige telefone para assinaturas.';
        }

        if (str_contains(strtolower($message), 'billing') && str_contains(strtolower($message), 'value')) {
            return 'Informe o endereço de cobrança completo (CEP, rua, número, cidade e UF).';
        }

        return $message;
    }

    private function amountInCents(Plan $plan): int
    {
        return (int) round(((float) $plan->price) * 100);
    }

    private function buildPhonesPayload(?string $phone): ?array
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) < 10) {
            return null;
        }

        return [
            'mobile_phone' => [
                'country_code' => '55',
                'area_code' => substr($digits, 0, 2),
                'number' => substr($digits, 2),
            ],
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.pagarme.base_url', 'https://api.pagar.me/core/v5'), '/');
    }

    private function formatError(Response $response): string
    {
        $body = $response->json();
        $errors = collect((array) data_get($body, 'errors', []))
            ->map(function ($error) {
                if (is_string($error)) {
                    return $error;
                }

                $field = data_get($error, 'field') ?: data_get($error, 'parameter_name');
                $message = data_get($error, 'message') ?: json_encode($error);

                return filled($field) ? "{$field}: {$message}" : (string) $message;
            })
            ->filter()
            ->implode(' ');

        $message = data_get($body, 'message') ?: $errors ?: $response->body();

        if (is_string($message) && str_contains($message, 'validation.required')) {
            $message = 'Dados obrigatórios não informados ao Pagar.me. Verifique CPF, e-mail e cartão.';
        }

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
