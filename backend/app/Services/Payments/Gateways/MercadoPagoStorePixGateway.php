<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\CardChargeResult;
use App\Contracts\PixChargeResult;
use App\Contracts\StorePixGateway;
use App\Models\Order;
use App\Models\StorePaymentProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MercadoPagoStorePixGateway implements StorePixGateway
{
    public function provider(): string
    {
        return 'mercadopago';
    }

    public function testConnection(StorePaymentProvider $connection): void
    {
        $token = $connection->credential('access_token');

        if (blank($token)) {
            throw new RuntimeException('Informe o Access Token do Mercado Pago.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(20)
            ->get('https://api.mercadopago.com/users/me');

        if ($response->failed()) {
            throw new RuntimeException('Mercado Pago: credenciais inválidas.');
        }
    }

    public function createPixCharge(Order $order, StorePaymentProvider $connection): PixChargeResult
    {
        $token = $connection->credential('access_token');

        if (blank($token)) {
            throw new RuntimeException('Mercado Pago da loja não configurado.');
        }

        $order->loadMissing('store');
        $expiresIn = (int) config('payments.pix_expires_in', 1800);

        $payload = [
            'transaction_amount' => round((float) $order->total_amount, 2),
            'description' => sprintf('Pedido #%s - %s', $order->display_code, $order->store?->name ?: 'Loja'),
            'payment_method_id' => 'pix',
            'payer' => [
                'email' => 'cliente+pedido'.$order->id.'@partiumenu.local',
                'first_name' => strtok((string) ($order->customer_name ?: 'Cliente'), ' ') ?: 'Cliente',
            ],
            'metadata' => [
                'type' => 'order_payment',
                'provider' => 'mercadopago',
                'order_id' => (string) $order->id,
                'store_id' => (string) $order->store_id,
            ],
            'date_of_expiration' => now()->addSeconds($expiresIn)->toIso8601String(),
        ];

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-Idempotency-Key' => 'order-'.$order->id.'-'.now()->timestamp])
            ->timeout(20)
            ->post('https://api.mercadopago.com/v1/payments', $payload);

        if ($response->failed()) {
            $message = data_get($response->json(), 'message') ?? $response->body();
            throw new RuntimeException('Mercado Pago: '.$message);
        }

        $body = $response->json();

        return new PixChargeResult(
            externalOrderId: (string) data_get($body, 'id'),
            externalChargeId: (string) data_get($body, 'id'),
            qrCode: data_get($body, 'point_of_interaction.transaction_data.qr_code'),
            qrCodeUrl: data_get($body, 'point_of_interaction.transaction_data.ticket_url'),
            expiresAt: data_get($body, 'date_of_expiration') ?? now()->addSeconds($expiresIn),
        );
    }

    public function fetchOrderStatus(StorePaymentProvider $connection, string $externalOrderId): ?string
    {
        $token = $connection->credential('access_token');

        if (blank($token) || blank($externalOrderId)) {
            return null;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(20)
            ->get('https://api.mercadopago.com/v1/payments/'.$externalOrderId);

        if ($response->failed()) {
            return null;
        }

        return strtolower((string) data_get($response->json(), 'status', ''));
    }

    public function handleWebhook(array $payload, string $eventType): ?int
    {
        $data = (array) ($payload['data'] ?? $payload);
        $metadata = (array) data_get($data, 'metadata', []);

        if (data_get($metadata, 'type') !== 'order_payment') {
            return null;
        }

        return (int) data_get($metadata, 'order_id');
    }

    public function supportsCardPayments(): bool
    {
        return false;
    }

    public function createCardToken(StorePaymentProvider $connection, array $card): string
    {
        throw new RuntimeException('Cartão online disponível apenas com Pagar.me.');
    }

    public function createCardCharge(
        Order $order,
        StorePaymentProvider $connection,
        string $cardToken,
        int $installments = 1
    ): CardChargeResult {
        throw new RuntimeException('Cartão online disponível apenas com Pagar.me.');
    }
}
