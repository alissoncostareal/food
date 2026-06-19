<?php

namespace App\Services;

use App\Events\NewOrderPlaced;
use App\Events\OrderPaymentConfirmed;
use App\Events\OrderPaymentRefunded;
use App\Events\OrderUpdated;
use App\Exceptions\PaymentRefundException;
use App\Jobs\ExpireUnpaidPixOrder;
use App\Models\Order;
use App\Models\Store;
use App\Models\StorePaymentProvider;
use App\Services\Payments\StorePaymentConnectionService;
use App\Services\Payments\StorePixGatewayResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\BrazilPhone;
use RuntimeException;

class OrderPixPaymentService
{
    public const STATUS_NOT_REQUIRED = 'not_required';
    public const STATUS_AWAITING = 'awaiting_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_REFUND_PENDING = 'refund_pending';

    public function __construct(
        private readonly StorePaymentConnectionService $connections,
        private readonly StorePixGatewayResolver $gatewayResolver,
        private readonly OrderStockService $stock,
    ) {}

    public function isOnlineMethod(string $method): bool
    {
        return in_array($method, config('payments.online_methods', []), true);
    }

    public function storeAcceptsOnlinePayments(Store $store, string $method): bool
    {
        if (! $store->online_payments_enabled) {
            return false;
        }

        if (! $this->connections->paymentReady($store)) {
            return false;
        }

        return $store->acceptsPaymentMethod($method);
    }

    public function storeAcceptsCardOnline(Store $store): bool
    {
        if (! $this->storeAcceptsOnlinePayments($store, Store::PAYMENT_CREDIT_CARD_ONLINE)) {
            return false;
        }

        $connection = $this->connections->activePixProvider($store);

        return $connection !== null
            && $connection->provider === 'pagarme'
            && $this->gatewayResolver->resolve($connection)->supportsCardPayments();
    }

    public function createCardToken(Store $store, array $card): string
    {
        $connection = $this->connections->activePixProvider($store);

        if (! $connection) {
            throw new RuntimeException('Nenhum gateway conectado para esta loja.');
        }

        $gateway = $this->gatewayResolver->resolve($connection);

        if (! $gateway->supportsCardPayments()) {
            throw new RuntimeException('Cartão online disponível apenas com Pagar.me.');
        }

        return $gateway->createCardToken($connection, $card);
    }

    public function createCardCharge(Order $order, string $cardToken, int $installments = 1): array
    {
        $order->loadMissing('store');

        $connection = $this->connections->activePixProvider($order->store);

        if (! $connection) {
            throw new RuntimeException('Nenhum gateway conectado para esta loja.');
        }

        $gateway = $this->gatewayResolver->resolve($connection);

        if (! $gateway->supportsCardPayments()) {
            throw new RuntimeException('Cartão online disponível apenas com Pagar.me.');
        }

        $result = $gateway->createCardCharge($order, $connection, $cardToken, $installments);

        $order->forceFill([
            'payment_channel' => 'online',
            'payment_provider' => $connection->provider,
            'payment_external_order_id' => $result->externalOrderId,
            'payment_external_charge_id' => $result->externalChargeId,
            'pagarme_order_id' => $connection->provider === 'pagarme' ? $result->externalOrderId : null,
            'pagarme_charge_id' => $connection->provider === 'pagarme' ? $result->externalChargeId : null,
        ])->save();

        if ($result->isPaid()) {
            $this->markPaid($order->fresh(), $result->externalChargeId);
        } elseif ($result->isPending()) {
            $order->forceFill(['payment_status' => self::STATUS_AWAITING])->save();
        } else {
            $this->failOnlineOrder($order->fresh(), $result->failureMessage);
            throw new RuntimeException($result->failureMessage ?: 'Pagamento recusado pelo emissor do cartão.');
        }

        return $this->paymentPayload($order->fresh());
    }

    public function failOnlineOrder(Order $order, ?string $message = null): void
    {
        if (in_array($order->payment_status, [self::STATUS_PAID, self::STATUS_EXPIRED], true)) {
            return;
        }

        $order->forceFill([
            'payment_status' => self::STATUS_FAILED,
            'status' => 'canceled',
        ])->save();

        $this->stock->restoreIfNeeded($order->fresh());
    }

    public function createPixCharge(Order $order): array
    {
        $order->loadMissing('store');

        $connection = $this->connections->activePixProvider($order->store);

        if (! $connection) {
            throw new RuntimeException('Nenhum gateway Pix conectado para esta loja.');
        }

        $gateway = $this->gatewayResolver->resolve($connection);
        $result = $gateway->createPixCharge($order, $connection);

        if (blank($result->qrCode)) {
            throw new RuntimeException('Gateway não retornou QR Code Pix.');
        }

        $expiresAt = $this->normalizeExpiresAt($result->expiresAt);

        $order->forceFill([
            'payment_status' => self::STATUS_AWAITING,
            'payment_channel' => 'online',
            'payment_provider' => $connection->provider,
            'payment_external_order_id' => $result->externalOrderId,
            'payment_external_charge_id' => $result->externalChargeId,
            'pagarme_order_id' => $connection->provider === 'pagarme' ? $result->externalOrderId : null,
            'pagarme_charge_id' => $connection->provider === 'pagarme' ? $result->externalChargeId : null,
            'pix_qr_code' => $result->qrCode,
            'pix_qr_code_url' => $result->qrCodeUrl,
            'payment_expires_at' => $expiresAt,
        ])->save();

        ExpireUnpaidPixOrder::dispatch($order->id)->delay(
            $expiresAt->copy()->addSeconds(15)
        );

        return $this->paymentPayload($order->fresh());
    }

    public function markPaid(Order $order, ?string $chargeId = null): bool
    {
        if ($order->payment_status === self::STATUS_PAID) {
            return false;
        }

        $previousPaymentStatus = $order->payment_status;
        $shouldNotifyStore = in_array($previousPaymentStatus, [self::STATUS_AWAITING, self::STATUS_EXPIRED], true);

        DB::transaction(function () use ($order, $chargeId, $previousPaymentStatus) {
            $order->refresh();

            if ($order->payment_status === self::STATUS_PAID) {
                return;
            }

            $updates = [
                'payment_status' => self::STATUS_PAID,
                'payment_paid_at' => now(),
                'payment_external_charge_id' => $chargeId ?: $order->payment_external_charge_id,
                'pagarme_charge_id' => $order->payment_provider === 'pagarme'
                    ? ($chargeId ?: $order->pagarme_charge_id)
                    : $order->pagarme_charge_id,
            ];

            if (
                $order->status === 'canceled'
                && in_array($previousPaymentStatus, [self::STATUS_AWAITING, self::STATUS_EXPIRED], true)
            ) {
                $updates['status'] = 'pending';
            }

            $order->forceFill($updates)->save();
        });

        $order->refresh();

        if ($shouldNotifyStore) {
            $order->load(['items.product', 'user', 'deliveryArea', 'coupon', 'store']);
            event(new NewOrderPlaced($order));
        }

        event(new OrderPaymentConfirmed($order));

        return true;
    }

    public function applyMercadoPagoPayment(Order $order, array $paymentBody): bool
    {
        $status = strtolower((string) data_get($paymentBody, 'status', ''));
        $chargeId = (string) (data_get($paymentBody, 'id') ?: '');

        if (in_array($status, ['paid', 'approved', 'confirmed', 'received', 'accredited'], true)) {
            return $this->markPaid($order, $chargeId !== '' ? $chargeId : null);
        }

        if (in_array($status, ['refunded', 'charged_back'], true)) {
            return $this->markRefunded($order);
        }

        if ($status === 'expired' && $this->canExpireFromRemoteStatus($order)) {
            return $this->markExpired($order);
        }

        if (in_array($status, ['cancelled', 'canceled', 'rejected', 'failed'], true)) {
            if ($this->canExpireFromRemoteStatus($order)) {
                if (in_array($status, ['rejected', 'failed'], true)) {
                    $this->markFailed($order);
                } else {
                    $this->markExpired($order);
                }
            }

            return true;
        }

        return false;
    }

    public function markFailed(Order $order): void
    {
        if (in_array($order->payment_status, [self::STATUS_PAID, self::STATUS_EXPIRED], true)) {
            return;
        }

        $order->forceFill([
            'payment_status' => self::STATUS_FAILED,
            'status' => $order->status === 'pending' ? 'canceled' : $order->status,
        ])->save();

        $this->stock->restoreIfNeeded($order->fresh());
    }

    public function markExpired(Order $order): bool
    {
        if ($order->payment_status !== self::STATUS_AWAITING) {
            return false;
        }

        $previousStatus = $order->status;

        $order->forceFill([
            'payment_status' => self::STATUS_EXPIRED,
            'status' => 'canceled',
        ])->save();

        $order = $order->fresh(['items.product', 'user', 'deliveryArea', 'coupon', 'store']);

        $this->stock->restoreIfNeeded($order);

        if ($previousStatus !== 'canceled') {
            event(new OrderUpdated($order, $previousStatus));
        }

        return true;
    }

    public function handleWebhookPayload(array $payload, string $eventType): bool
    {
        $metadata = (array) data_get($payload, 'metadata', []);

        if (data_get($metadata, 'type') !== 'order_payment') {
            return false;
        }

        $orderId = (int) data_get($metadata, 'order_id');
        $provider = (string) data_get($metadata, 'provider', 'pagarme');

        if ($orderId <= 0) {
            return false;
        }

        $order = Order::find($orderId);

        if (! $order) {
            Log::warning('Payment webhook: pedido não encontrado', ['order_id' => $orderId]);

            return false;
        }

        try {
            $gateway = $this->gatewayResolver->resolve(new StorePaymentProvider(['provider' => $provider]));
        } catch (\Throwable) {
            return false;
        }

        $normalized = strtolower($eventType);
        $chargeId = (string) (data_get($payload, 'id') ?? data_get($payload, 'charge.id') ?? '');

        if (str_contains($normalized, 'paid')) {
            return $this->markPaid($order, $chargeId ?: null);
        }

        if (str_contains($normalized, 'refund')) {
            return $this->markRefunded($order);
        }

        if (str_contains($normalized, 'failed')) {
            $this->markFailed($order);

            return true;
        }

        if (str_contains($normalized, 'canceled') || str_contains($normalized, 'expired')) {
            return $this->markExpired($order);
        }

        return false;
    }

    public function syncRemoteStatus(Order $order): void
    {
        if (! in_array($order->payment_status, [self::STATUS_AWAITING, self::STATUS_EXPIRED], true)) {
            return;
        }

        $externalId = $order->payment_external_order_id ?: $order->pagarme_order_id;

        if (filled($externalId) && filled($order->payment_provider)) {
            $connection = StorePaymentProvider::query()
                ->where('store_id', $order->store_id)
                ->where('provider', $order->payment_provider)
                ->where('status', StorePaymentProvider::STATUS_CONNECTED)
                ->first();

            if ($connection) {
                $gateway = $this->gatewayResolver->resolve($connection);
                $status = strtolower((string) $gateway->fetchOrderStatus($connection, $externalId));

                if (in_array($status, ['paid', 'approved', 'confirmed', 'received', 'accredited'], true)) {
                    $this->markPaid($order, $order->payment_external_charge_id);

                    return;
                }

                if ($status === 'expired') {
                    if ($this->canExpireFromRemoteStatus($order)) {
                        $this->markExpired($order);
                    }

                    return;
                }

                if (in_array($status, ['cancelled', 'canceled', 'rejected', 'failed'], true)) {
                    if ($this->canExpireFromRemoteStatus($order)) {
                        if (in_array($status, ['rejected', 'failed'], true)) {
                            $this->markFailed($order);
                        } else {
                            $this->markExpired($order);
                        }
                    }

                    return;
                }
            }
        }

        if ($order->payment_status === self::STATUS_AWAITING && $this->shouldExpireLocally($order)) {
            $this->markExpired($order);
        }
    }

    private function shouldExpireLocally(Order $order): bool
    {
        if (! $order->payment_expires_at) {
            return false;
        }

        // Tolerância para atraso do webhook / consulta no gateway.
        return now()->gte($order->payment_expires_at->copy()->addSeconds(45));
    }

    private function canExpireFromRemoteStatus(Order $order): bool
    {
        if (! $order->payment_expires_at) {
            return true;
        }

        return now()->gte($order->payment_expires_at);
    }

    public function requiresRefundOnCancel(Order $order): bool
    {
        if (in_array($order->payment_status, [self::STATUS_REFUNDED, self::STATUS_REFUND_PENDING], true)) {
            return false;
        }

        if ($order->payment_status !== self::STATUS_PAID) {
            return false;
        }

        return $order->payment_channel === 'online'
            && $this->isOnlineMethod((string) $order->payment_method);
    }

    public function markRefundPending(Order $order): bool
    {
        if (in_array($order->payment_status, [self::STATUS_REFUNDED, self::STATUS_REFUND_PENDING], true)) {
            return false;
        }

        $order->forceFill([
            'payment_status' => self::STATUS_REFUND_PENDING,
        ])->save();

        return true;
    }

    public function refundPaidOnlineOrder(Order $order): void
    {
        if ($order->payment_status === self::STATUS_REFUNDED) {
            return;
        }

        if ($order->payment_status !== self::STATUS_PAID) {
            throw new RuntimeException('Este pedido não possui pagamento online confirmado para estorno.');
        }

        if ($order->payment_channel !== 'online' || ! $this->isOnlineMethod((string) $order->payment_method)) {
            throw new RuntimeException('Estorno automático disponível apenas para pagamentos online.');
        }

        $order->loadMissing('store');

        if (blank($order->payment_provider)) {
            throw new RuntimeException('Pedido sem gateway de pagamento registrado. Estorne manualmente no painel do provedor.');
        }

        $connection = StorePaymentProvider::query()
            ->where('store_id', $order->store_id)
            ->where('provider', $order->payment_provider)
            ->where('status', StorePaymentProvider::STATUS_CONNECTED)
            ->first();

        if (! $connection) {
            throw new RuntimeException(
                'Gateway de pagamento da loja não está conectado. Estorne manualmente no painel do provedor antes de cancelar.'
            );
        }

        $gateway = $this->gatewayResolver->resolve($connection);

        try {
            $gateway->refundCharge($order, $connection);
        } catch (RuntimeException $e) {
            if ($this->isInsufficientBalanceRefundError($e->getMessage())) {
                throw new PaymentRefundException(
                    'Sua conta no Mercado Pago não tem saldo disponível para estornar agora. '
                    .'Aguarde a liberação do Pix na conta, deposite saldo no Mercado Pago ou cancele informando que fará o estorno manualmente no painel.',
                    allowsManualCancel: true,
                );
            }

            throw $e;
        }

        $this->markRefunded($order);
    }

    private function isInsufficientBalanceRefundError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'enough available money')
            || str_contains($normalized, 'saldo insuficiente')
            || str_contains($normalized, 'saldo dispon')
            || str_contains($normalized, 'insuficiente')
            || str_contains($normalized, 'insufficient')
            || str_contains($normalized, '3020');
    }

    public function markRefunded(Order $order): bool
    {
        if ($order->payment_status === self::STATUS_REFUNDED) {
            return false;
        }

        $order->forceFill([
            'payment_status' => self::STATUS_REFUNDED,
            'payment_refunded_at' => now(),
        ])->save();

        $order->refresh()->loadMissing(['store']);

        event(new OrderPaymentRefunded($order));

        return true;
    }

    public function paymentPayload(Order $order): array
    {
        return [
            'method' => $order->payment_method,
            'channel' => $order->payment_channel,
            'provider' => $order->payment_provider,
            'status' => $order->payment_status,
            'amount' => (float) $order->total_amount,
            'expires_at' => $order->payment_expires_at?->toIso8601String(),
            'paid_at' => $order->payment_paid_at,
            'refunded_at' => $order->payment_refunded_at?->toIso8601String(),
            'pix' => filled($order->pix_qr_code) ? [
                'qr_code' => $order->pix_qr_code,
                'qr_code_url' => $order->pix_qr_code_url,
            ] : null,
        ];
    }

    public function verifyCustomerAccess(Order $order, ?string $phoneDigits): bool
    {
        return BrazilPhone::matches($order->customer_phone, $phoneDigits);
    }

    private function normalizeExpiresAt(mixed $expiresAt): Carbon
    {
        $expiresIn = max(1800, (int) config('payments.pix_expires_in', 1800));
        $fallback = now()->addSeconds($expiresIn);

        try {
            if ($expiresAt instanceof Carbon) {
                $parsed = $expiresAt->copy();
            } elseif (filled($expiresAt)) {
                $parsed = Carbon::parse($expiresAt);
            } else {
                return $fallback;
            }

            if ($parsed->isFuture() && $parsed->greaterThan(now()->addMinute())) {
                return $parsed;
            }
        } catch (\Throwable) {
            // Usa fallback calculado no servidor.
        }

        return $fallback;
    }
}
