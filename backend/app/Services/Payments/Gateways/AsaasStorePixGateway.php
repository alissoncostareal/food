<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\CardChargeResult;
use App\Contracts\PixChargeResult;
use App\Contracts\StorePixGateway;
use App\Models\Order;
use App\Models\StorePaymentProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AsaasStorePixGateway implements StorePixGateway
{
    public function provider(): string
    {
        return 'asaas';
    }

    public function testConnection(StorePaymentProvider $connection): void
    {
        $response = Http::withHeaders([
            'access_token' => (string) $connection->credential('api_key'),
        ])
            ->acceptJson()
            ->timeout(20)
            ->get($this->baseUrl($connection).'/finance/balance');

        if ($response->failed()) {
            throw new RuntimeException('Asaas: credenciais inválidas.');
        }
    }

    public function createPixCharge(Order $order, StorePaymentProvider $connection): PixChargeResult
    {
        $order->loadMissing('store');
        $apiKey = (string) $connection->credential('api_key');
        $baseUrl = $this->baseUrl($connection);

        $customerId = $this->ensureCustomer($order, $connection, $baseUrl, $apiKey);
        $expiresIn = (int) config('payments.pix_expires_in', 1800);

        $payload = [
            'customer' => $customerId,
            'billingType' => 'PIX',
            'value' => round((float) $order->total_amount, 2),
            'dueDate' => now()->addSeconds($expiresIn)->format('Y-m-d'),
            'description' => sprintf('Pedido #%s - %s', $order->display_code, $order->store?->name ?: 'Loja'),
            'externalReference' => (string) $order->id,
        ];

        $response = Http::withHeaders(['access_token' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post($baseUrl.'/payments', $payload);

        if ($response->failed()) {
            $message = data_get($response->json(), 'errors.0.description') ?? $response->body();
            throw new RuntimeException('Asaas: '.$message);
        }

        $body = $response->json();
        $paymentId = (string) data_get($body, 'id');

        $pixResponse = Http::withHeaders(['access_token' => $apiKey])
            ->acceptJson()
            ->timeout(20)
            ->get($baseUrl.'/payments/'.$paymentId.'/pixQrCode');

        $pix = $pixResponse->json() ?? [];

        return new PixChargeResult(
            externalOrderId: $paymentId,
            externalChargeId: $paymentId,
            qrCode: data_get($pix, 'payload'),
            qrCodeUrl: data_get($pix, 'encodedImage') ? 'data:image/png;base64,'.data_get($pix, 'encodedImage') : null,
            expiresAt: now()->addSeconds($expiresIn),
        );
    }

    public function fetchOrderStatus(StorePaymentProvider $connection, string $externalOrderId): ?string
    {
        $apiKey = (string) $connection->credential('api_key');

        if (blank($apiKey) || blank($externalOrderId)) {
            return null;
        }

        $response = Http::withHeaders(['access_token' => $apiKey])
            ->acceptJson()
            ->timeout(20)
            ->get($this->baseUrl($connection).'/payments/'.$externalOrderId);

        if ($response->failed()) {
            return null;
        }

        return strtolower((string) data_get($response->json(), 'status', ''));
    }

    public function handleWebhook(array $payload, string $eventType): ?int
    {
        $payment = (array) ($payload['payment'] ?? $payload);
        $orderId = (int) data_get($payment, 'externalReference');

        return $orderId > 0 ? $orderId : null;
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
        $apiKey = (string) $connection->credential('api_key');
        $paymentId = (string) ($order->payment_external_charge_id ?: $order->payment_external_order_id ?: '');

        if (blank($apiKey) || blank($paymentId)) {
            throw new RuntimeException('Asaas: pagamento sem identificador para estorno.');
        }

        $currentStatus = strtolower((string) $this->fetchOrderStatus($connection, $paymentId));

        if (in_array($currentStatus, ['refunded', 'refund_requested'], true)) {
            return;
        }

        $response = Http::withHeaders(['access_token' => $apiKey])
            ->acceptJson()
            ->timeout(30)
            ->post($this->baseUrl($connection).'/payments/'.$paymentId.'/refund');

        if ($response->successful()) {
            return;
        }

        $refreshedStatus = strtolower((string) $this->fetchOrderStatus($connection, $paymentId));

        if (in_array($refreshedStatus, ['refunded', 'refund_requested'], true)) {
            return;
        }

        $message = data_get($response->json(), 'errors.0.description') ?? $response->body();

        throw new RuntimeException('Asaas: '.$message);
    }

    private function ensureCustomer(Order $order, StorePaymentProvider $connection, string $baseUrl, string $apiKey): string
    {
        $phone = preg_replace('/\D+/', '', (string) $order->customer_phone) ?? '';

        $payload = [
            'name' => $order->customer_name ?: 'Cliente',
            'mobilePhone' => $phone,
            'cpfCnpj' => '00000000000',
            'notificationDisabled' => true,
        ];

        $response = Http::withHeaders(['access_token' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post($baseUrl.'/customers', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Asaas: não foi possível criar cliente para cobrança.');
        }

        return (string) data_get($response->json(), 'id');
    }

    private function baseUrl(StorePaymentProvider $connection): string
    {
        return $connection->credential('environment') === 'production'
            ? 'https://api.asaas.com/v3'
            : 'https://sandbox.asaas.com/api/v3';
    }
}
