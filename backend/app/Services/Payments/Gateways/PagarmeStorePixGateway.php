<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\CardChargeResult;
use App\Contracts\PixChargeResult;
use App\Contracts\StorePixGateway;
use App\Models\Order;
use App\Models\StorePaymentProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PagarmeStorePixGateway implements StorePixGateway
{
    public function provider(): string
    {
        return 'pagarme';
    }

    public function testConnection(StorePaymentProvider $connection): void
    {
        $secret = $connection->credential('secret_key');

        if (blank($secret)) {
            throw new RuntimeException('Informe a Secret Key do Pagar.me.');
        }

        $response = Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->timeout(20)
            ->get($this->baseUrl().'/customers', ['page' => 1, 'size' => 1]);

        if ($response->failed()) {
            throw new RuntimeException($this->formatError($response));
        }
    }

    public function createPixCharge(Order $order, StorePaymentProvider $connection): PixChargeResult
    {
        $secret = $connection->credential('secret_key');

        if (blank($secret)) {
            throw new RuntimeException('Pagar.me da loja não configurado.');
        }

        $order->loadMissing('store');
        $amountCents = (int) round(((float) $order->total_amount) * 100);
        $expiresIn = (int) config('payments.pix_expires_in', 1800);

        if ($amountCents < 50) {
            throw new RuntimeException('Pagar.me: valor mínimo para Pix é R$ 0,50.');
        }

        $payload = [
            'customer' => $this->buildCustomerPayload($order),
            'items' => [[
                'amount' => $amountCents,
                'description' => sprintf('Pedido #%s - %s', $order->display_code, $order->store?->name ?: 'Loja'),
                'quantity' => 1,
                'code' => 'order_'.$order->id,
            ]],
            'payments' => [[
                'payment_method' => 'pix',
                'pix' => ['expires_in' => $expiresIn],
            ]],
            'metadata' => [
                'type' => 'order_payment',
                'provider' => 'pagarme',
                'order_id' => (string) $order->id,
                'store_id' => (string) $order->store_id,
            ],
        ];

        $response = Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post($this->baseUrl().'/orders', $payload);

        if ($response->failed()) {
            throw new RuntimeException($this->formatError($response));
        }

        $body = $response->json();
        $transaction = data_get($body, 'charges.0.last_transaction', []);

        return new PixChargeResult(
            externalOrderId: (string) data_get($body, 'id'),
            externalChargeId: data_get($body, 'charges.0.id'),
            qrCode: data_get($transaction, 'qr_code'),
            qrCodeUrl: data_get($transaction, 'qr_code_url'),
            expiresAt: data_get($transaction, 'expires_at') ?? now()->addSeconds($expiresIn),
        );
    }

    public function fetchOrderStatus(StorePaymentProvider $connection, string $externalOrderId): ?string
    {
        $secret = $connection->credential('secret_key');

        if (blank($secret) || blank($externalOrderId)) {
            return null;
        }

        $response = Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->timeout(20)
            ->get($this->baseUrl().'/orders/'.$externalOrderId);

        if ($response->failed()) {
            return null;
        }

        return strtolower((string) data_get($response->json(), 'charges.0.status', ''));
    }

    public function handleWebhook(array $payload, string $eventType): ?int
    {
        if (data_get($payload, 'metadata.type') !== 'order_payment') {
            return null;
        }

        if (data_get($payload, 'metadata.provider') && data_get($payload, 'metadata.provider') !== 'pagarme') {
            return null;
        }

        return (int) data_get($payload, 'metadata.order_id');
    }

    public function supportsCardPayments(): bool
    {
        return true;
    }

    public function createCardToken(StorePaymentProvider $connection, array $card): string
    {
        $publicKey = $connection->credential('public_key');

        if (blank($publicKey)) {
            throw new RuntimeException('Informe a Public Key do Pagar.me da loja.');
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
            ->timeout(20)
            ->post(
                $this->baseUrl().'/tokens?appId='.urlencode((string) $publicKey),
                $payload
            );

        if ($response->failed()) {
            throw new RuntimeException($this->formatError($response));
        }

        $token = (string) data_get($response->json(), 'id');

        if (blank($token)) {
            throw new RuntimeException('Pagar.me não retornou token do cartão.');
        }

        return $token;
    }

    public function createCardCharge(
        Order $order,
        StorePaymentProvider $connection,
        string $cardToken,
        int $installments = 1
    ): CardChargeResult {
        $secret = $connection->credential('secret_key');

        if (blank($secret)) {
            throw new RuntimeException('Pagar.me da loja não configurado.');
        }

        $order->loadMissing('store');
        $amountCents = (int) round(((float) $order->total_amount) * 100);
        $descriptor = Str::limit(
            preg_replace('/\s+/', ' ', (string) ($order->store?->name ?: 'PARTIUMENU')),
            13,
            ''
        ) ?: 'PARTIUMENU';

        $payload = [
            'customer' => $this->buildCustomerPayload($order),
            'items' => [[
                'amount' => $amountCents,
                'description' => sprintf('Pedido #%s - %s', $order->display_code, $order->store?->name ?: 'Loja'),
                'quantity' => 1,
                'code' => 'order_'.$order->id,
            ]],
            'payments' => [[
                'payment_method' => 'credit_card',
                'credit_card' => [
                    'installments' => max(1, min(12, $installments)),
                    'statement_descriptor' => $descriptor,
                    'card_token' => $cardToken,
                ],
            ]],
            'metadata' => [
                'type' => 'order_payment',
                'provider' => 'pagarme',
                'order_id' => (string) $order->id,
                'store_id' => (string) $order->store_id,
            ],
        ];

        $response = Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->post($this->baseUrl().'/orders', $payload);

        if ($response->failed()) {
            throw new RuntimeException($this->formatError($response));
        }

        $body = $response->json();
        $chargeStatus = strtolower((string) (
            data_get($body, 'charges.0.status')
            ?? data_get($body, 'charges.0.last_transaction.status')
            ?? data_get($body, 'status')
            ?? 'failed'
        ));

        $failureMessage = data_get($body, 'charges.0.last_transaction.acquirer_message')
            ?? data_get($body, 'charges.0.last_transaction.gateway_response.errors.0.message');

        return new CardChargeResult(
            externalOrderId: (string) data_get($body, 'id'),
            externalChargeId: data_get($body, 'charges.0.id'),
            chargeStatus: $chargeStatus,
            failureMessage: is_string($failureMessage) ? $failureMessage : null,
        );
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.pagarme.base_url', 'https://api.pagar.me/core/v5'), '/');
    }

    private function formatError($response): string
    {
        $body = $response->json();
        $message = data_get($body, 'message') ?? data_get($body, 'errors.0.message') ?? $response->body();

        return 'Pagar.me: '.$message;
    }

    private function buildCustomerPayload(Order $order): array
    {
        $order->loadMissing(['store', 'user']);
        $digits = preg_replace('/\D+/', '', (string) $order->customer_phone) ?: (string) $order->id;
        $email = $order->user?->email;

        if (blank($email) || str_ends_with(strtolower((string) $email), '.local')) {
            $email = "pedido+{$order->id}.{$digits}@customers.partiumenu.com.br";
        }

        return [
            'name' => $order->customer_name ?: 'Cliente',
            'email' => $email,
            'type' => 'individual',
            'phones' => ['mobile_phone' => $this->parsePhone($order->customer_phone)],
        ];
    }

    private function parsePhone(?string $phone): array
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (str_starts_with($digits, '55') && strlen($digits) > 11) {
            $digits = substr($digits, 2);
        }

        return [
            'country_code' => '55',
            'area_code' => substr($digits, 0, 2) ?: '11',
            'number' => substr($digits, 2) ?: '999999999',
        ];
    }
}
