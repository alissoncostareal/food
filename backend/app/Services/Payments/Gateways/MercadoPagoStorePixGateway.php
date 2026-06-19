<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\CardChargeResult;
use App\Contracts\PixChargeResult;
use App\Contracts\StorePixGateway;
use App\Models\Order;
use App\Models\StorePaymentProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
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

        $order->loadMissing(['store', 'user']);
        $expiresIn = max(1800, (int) config('payments.pix_expires_in', 1800));
        $expiresAt = now(config('app.timezone', 'America/Sao_Paulo'))->addSeconds($expiresIn);
        $store = $order->store;

        $payload = [
            'transaction_amount' => round((float) $order->total_amount, 2),
            'description' => sprintf('Pedido #%s - %s', $order->display_code, $store?->name ?: 'Loja'),
            'payment_method_id' => 'pix',
            'external_reference' => 'order-'.$order->id,
            'payer' => [
                'email' => $this->buildPayerEmail($order, $token),
                'first_name' => strtok((string) ($order->customer_name ?: 'Cliente'), ' ') ?: 'Cliente',
            ],
            'metadata' => [
                'type' => 'order_payment',
                'provider' => 'mercadopago',
                'order_id' => (string) $order->id,
                'store_id' => (string) $order->store_id,
            ],
            'date_of_expiration' => $expiresAt->clone()->utc()->format('Y-m-d\TH:i:s.000\Z'),
        ];

        if (filled($store?->slug)) {
            $payload['notification_url'] = rtrim((string) config('app.url'), '/')
                .'/api/v1/webhooks/payments/mercadopago/'.ltrim((string) $store->slug, '/');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-Idempotency-Key' => 'order-'.$order->id])
            ->timeout(20)
            ->post('https://api.mercadopago.com/v1/payments', $payload);

        if ($response->failed()) {
            throw new RuntimeException($this->formatError($response));
        }

        $body = $response->json();
        $mpStatus = strtolower((string) data_get($body, 'status', ''));

        if (in_array($mpStatus, ['rejected', 'cancelled'], true)) {
            throw new RuntimeException(
                'Mercado Pago: pagamento Pix recusado ('.data_get($body, 'status_detail', $mpStatus).').'
            );
        }

        $qrCode = data_get($body, 'point_of_interaction.transaction_data.qr_code');

        if (blank($qrCode)) {
            throw new RuntimeException(
                'Mercado Pago: Pix não retornou QR Code. Verifique se recebimentos Pix estão habilitados na conta.'
            );
        }

        return new PixChargeResult(
            externalOrderId: (string) data_get($body, 'id'),
            externalChargeId: (string) data_get($body, 'id'),
            qrCode: $qrCode,
            qrCodeUrl: null,
            expiresAt: $this->resolveExpiresAt(data_get($body, 'date_of_expiration'), $expiresAt),
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
            ->timeout(10)
            ->get('https://api.mercadopago.com/v1/payments/'.$externalOrderId);

        if ($response->failed()) {
            return null;
        }

        return strtolower((string) data_get($response->json(), 'status', ''));
    }

    public function handleWebhook(array $payload, string $eventType): ?int
    {
        $data = (array) (data_get($payload, 'data') ?? $payload);
        $metadata = (array) data_get($data, 'metadata', []);

        if (data_get($metadata, 'type') === 'order_payment') {
            $orderId = (int) data_get($metadata, 'order_id');

            if ($orderId > 0) {
                return $orderId;
            }
        }

        $paymentId = (string) (
            data_get($data, 'id')
            ?? data_get($payload, 'id')
            ?? ''
        );

        if ($paymentId !== '') {
            $orderId = Order::query()
                ->where('payment_provider', 'mercadopago')
                ->where('payment_external_order_id', $paymentId)
                ->value('id');

            if ($orderId) {
                return (int) $orderId;
            }
        }

        $externalReference = (string) data_get($data, 'external_reference', '');

        if (str_starts_with($externalReference, 'order-')) {
            return (int) substr($externalReference, strlen('order-'));
        }

        return null;
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

    public function refundCharge(Order $order, StorePaymentProvider $connection): void
    {
        $token = $connection->credential('access_token');
        $paymentId = (string) ($order->payment_external_charge_id ?: $order->payment_external_order_id ?: '');

        if (blank($token) || blank($paymentId)) {
            throw new RuntimeException('Mercado Pago: pagamento sem identificador para estorno.');
        }

        $currentStatus = strtolower((string) $this->fetchOrderStatus($connection, $paymentId));

        if (in_array($currentStatus, ['refunded', 'cancelled', 'canceled'], true)) {
            return;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-Idempotency-Key' => 'refund-order-'.$order->id])
            ->timeout(30)
            ->post('https://api.mercadopago.com/v1/payments/'.$paymentId.'/refunds', []);

        if ($response->successful()) {
            return;
        }

        $refreshedStatus = strtolower((string) $this->fetchOrderStatus($connection, $paymentId));

        if (in_array($refreshedStatus, ['refunded', 'cancelled', 'canceled'], true)) {
            return;
        }

        throw new RuntimeException($this->formatRefundError($response));
    }

    private function formatRefundError($response): string
    {
        $body = $response->json();
        $causes = collect((array) data_get($body, 'cause', []));
        $descriptions = $causes
            ->map(fn ($cause) => strtolower((string) (data_get($cause, 'description') ?: data_get($cause, 'code') ?: '')))
            ->filter();

        if ($descriptions->contains(fn ($text) => str_contains($text, 'enough available money') || str_contains($text, 'saldo'))) {
            return 'Mercado Pago: saldo insuficiente na conta para estornar este Pix.';
        }

        return $this->formatError($response);
    }

    private function buildPayerEmail(Order $order, string $accessToken): string
    {
        $storedEmail = $order->user?->email;

        if ($this->isAcceptablePayerEmail($storedEmail, $accessToken)) {
            return (string) $storedEmail;
        }

        $digits = preg_replace('/\D+/', '', (string) $order->customer_phone) ?: (string) $order->id;

        if ($this->isTestAccessToken($accessToken)) {
            return "test_user_{$digits}@testuser.com";
        }

        return "pedido+{$order->id}.{$digits}@customers.partiumenu.com.br";
    }

    private function isAcceptablePayerEmail(?string $email, string $accessToken): bool
    {
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $normalized = strtolower($email);

        if (str_ends_with($normalized, '.local')) {
            return false;
        }

        if ($this->isTestAccessToken($accessToken)) {
            return str_ends_with($normalized, '@testuser.com');
        }

        return ! str_ends_with($normalized, '@testuser.com');
    }

    private function isTestAccessToken(string $accessToken): bool
    {
        return str_starts_with($accessToken, 'TEST-');
    }

    private function resolveExpiresAt(mixed $remote, CarbonInterface $fallback): CarbonInterface
    {
        try {
            if (filled($remote)) {
                $parsed = Carbon::parse($remote);

                if ($parsed->isFuture() && $parsed->greaterThan(now()->addMinute())) {
                    return $parsed;
                }
            }
        } catch (\Throwable) {
            // Usa fallback calculado no servidor.
        }

        return $fallback->copy();
    }

    private function formatError($response): string
    {
        $body = $response->json();
        $causes = collect((array) data_get($body, 'cause', []))
            ->map(fn ($cause) => data_get($cause, 'description') ?: data_get($cause, 'code'))
            ->filter()
            ->implode(' ');

        $message = data_get($body, 'message');

        if (filled($causes)) {
            return 'Mercado Pago: '.trim(($message ? $message.' — ' : '').$causes);
        }

        return 'Mercado Pago: '.($message ?? $response->body());
    }
}
