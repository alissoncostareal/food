<?php

namespace App\Services;

use App\Events\NewOrderPlaced;
use App\Events\OrderUpdated;
use App\Models\IfoodWebhookEvent;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class IfoodOrderHandler
{
    public function __construct(
        private readonly IfoodService $ifood,
        private readonly IfoodOrderSyncService $ifoodSync
    ) {
    }

    public function handle(Store $store, array $payload): array
    {
        $eventId = (string) data_get($payload, 'id', '');
        $code = strtoupper((string) data_get($payload, 'code', ''));
        $orderId = (string) (data_get($payload, 'orderId') ?: data_get($payload, 'order_id', ''));

        if ($eventId !== '') {
            $existing = IfoodWebhookEvent::query()->where('event_id', $eventId)->first();

            if ($existing?->status === 'processed') {
                return ['action' => 'duplicate', 'event_id' => $eventId];
            }

            if ($existing) {
                $existing->update([
                    'code' => $code,
                    'ifood_order_id' => $orderId !== '' ? $orderId : null,
                    'status' => 'processing',
                    'error' => null,
                ]);
                $event = $existing;
            } else {
                $event = IfoodWebhookEvent::create([
                    'event_id' => $eventId,
                    'store_id' => $store->id,
                    'code' => $code,
                    'ifood_order_id' => $orderId !== '' ? $orderId : null,
                    'status' => 'processing',
                ]);
            }
        } else {
            $event = IfoodWebhookEvent::create([
                'event_id' => uniqid('ifood_', true),
                'store_id' => $store->id,
                'code' => $code,
                'ifood_order_id' => $orderId !== '' ? $orderId : null,
                'status' => 'processing',
            ]);
        }

        try {
            $result = match (true) {
                in_array($code, ['PLACED', 'PLC'], true) => $this->upsertOrder($store, $orderId, $code),
                in_array($code, ['CONFIRMED', 'CFM'], true) => $this->markConfirmed($store, $orderId),
                in_array($code, ['DISPATCHED', 'DSP'], true) => $this->syncLocalStatus($store, $orderId, 'shipped'),
                in_array($code, ['CONCLUDED', 'CON'], true) => $this->syncLocalStatus($store, $orderId, 'delivered'),
                in_array($code, ['READY_TO_PICKUP', 'RTP'], true) => $this->syncLocalStatus($store, $orderId, 'ready'),
                in_array($code, ['CANCELLED', 'CAN', 'CANCELED'], true) => $this->cancelOrder($store, $orderId),
                default => ['action' => 'ignored', 'code' => $code],
            };

            $event->update(['status' => 'processed']);

            return $result;
        } catch (Throwable $e) {
            $event->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            Log::error('iFood webhook processing failed', [
                'store_id' => $store->id,
                'event_id' => $eventId,
                'code' => $code,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function upsertOrder(Store $store, string $ifoodOrderId, string $code): array
    {
        if ($ifoodOrderId === '') {
            return ['action' => 'skipped', 'reason' => 'missing_order_id'];
        }

        $existing = Order::query()
            ->where('ifood_order_id', $ifoodOrderId)
            ->first();

        if ($existing) {
            return ['action' => 'exists', 'order_id' => $existing->id];
        }

        $details = $this->ifood->fetchOrderDetails($store, $ifoodOrderId);

        $order = DB::transaction(function () use ($store, $details, $ifoodOrderId) {
            $order = $this->createLocalOrder($store, $details, $ifoodOrderId);

            return $order->load(['items.product', 'user', 'store']);
        });

        $order = $this->ifoodSync->autoAcceptIfEnabled($order, $store->fresh());

        event(new NewOrderPlaced($order));

        return [
            'action' => 'created',
            'order_id' => $order->id,
            'code' => $code,
            'auto_confirmed' => (bool) $store->ifood_auto_confirm,
        ];
    }

    private function markConfirmed(Store $store, string $ifoodOrderId): array
    {
        $order = $this->findOrder($store, $ifoodOrderId);

        if (! $order) {
            return ['action' => 'not_found'];
        }

        $updates = [];

        if (blank($order->ifood_confirmed_at)) {
            $updates['ifood_confirmed_at'] = now();
        }

        if ($order->status === 'pending') {
            $updates['status'] = 'preparing';
        }

        if ($updates !== []) {
            $previousStatus = $order->status;
            $order->update($updates);

            if (($updates['status'] ?? null) === 'preparing') {
                event(new OrderUpdated($order->fresh(['items.product', 'user', 'store']), $previousStatus));
            }
        }

        return ['action' => 'confirmed', 'order_id' => $order->id];
    }

    private function syncLocalStatus(Store $store, string $ifoodOrderId, string $status): array
    {
        $order = $this->findOrder($store, $ifoodOrderId);

        if (! $order) {
            return ['action' => 'not_found'];
        }

        if ($order->status !== $status) {
            $previousStatus = $order->status;
            $order->update(['status' => $status]);
            event(new OrderUpdated($order->fresh(['items.product', 'user', 'store']), $previousStatus));
        }

        return ['action' => 'status_synced', 'order_id' => $order->id, 'status' => $status];
    }

    private function cancelOrder(Store $store, string $ifoodOrderId): array
    {
        if ($ifoodOrderId === '') {
            return ['action' => 'skipped', 'reason' => 'missing_order_id'];
        }

        $order = $this->findOrder($store, $ifoodOrderId);

        if (! $order) {
            return ['action' => 'not_found'];
        }

        if ($order->status !== 'canceled') {
            $previousStatus = $order->status;
            $order->update(['status' => 'canceled']);
            event(new OrderUpdated($order->fresh(['items.product', 'user', 'store']), $previousStatus));
        }

        return ['action' => 'canceled', 'order_id' => $order->id];
    }

    private function findOrder(Store $store, string $ifoodOrderId): ?Order
    {
        if ($ifoodOrderId === '') {
            return null;
        }

        return Order::query()
            ->where('store_id', $store->id)
            ->where('ifood_order_id', $ifoodOrderId)
            ->first();
    }

    private function createLocalOrder(Store $store, array $details, string $ifoodOrderId): Order
    {
        $customer = (array) data_get($details, 'customer', []);
        $customerName = data_get($customer, 'name')
            ?: trim(collect(data_get($customer, 'phones', []))->pluck('phone')->first() ?? '')
            ?: 'Cliente iFood';

        $phone = data_get($customer, 'phone.number')
            ?: data_get($customer, 'phones.0.phone')
            ?: data_get($customer, 'phone')
            ?: null;

        $delivery = (array) data_get($details, 'delivery', []);
        $address = (array) data_get($delivery, 'deliveryAddress', data_get($delivery, 'address', []));
        $orderType = strtoupper((string) data_get($details, 'orderType', 'DELIVERY'));
        $deliveredBy = strtoupper((string) (
            data_get($delivery, 'deliveredBy')
            ?: data_get($details, 'delivery.deliveredBy')
            ?: 'IFOOD'
        ));
        $fulfillmentType = in_array($orderType, ['TAKEOUT', 'INDOOR'], true) ? 'pickup' : 'delivery';

        $totalAmount = (float) (
            data_get($details, 'total.orderAmount')
            ?: data_get($details, 'total.subTotal')
            ?: 0
        );

        $deliveryFee = (float) (
            data_get($details, 'total.deliveryFee')
            ?: data_get($details, 'delivery.fee')
            ?: 0
        );

        $paymentMethod = $this->mapPaymentMethod($details);
        $displayId = (string) (data_get($details, 'displayId') ?: data_get($details, 'shortReference', ''));
        $deliveryLocalizer = (string) (
            data_get($customer, 'phone.localizer')
            ?: data_get($customer, 'phones.0.localizer')
            ?: data_get($details, 'delivery.pickupCode')
            ?: ''
        );

        $order = Order::create([
            'store_id' => $store->id,
            'order_source' => 'ifood',
            'ifood_order_id' => $ifoodOrderId,
            'ifood_display_id' => $displayId !== '' ? $displayId : null,
            'ifood_order_type' => $orderType,
            'ifood_delivered_by' => $deliveredBy,
            'ifood_delivery_localizer' => $deliveryLocalizer !== '' ? $deliveryLocalizer : null,
            'customer_name' => $customerName,
            'customer_phone' => $phone,
            'address' => data_get($address, 'streetName') ?: data_get($address, 'street'),
            'address_number' => data_get($address, 'streetNumber') ?: data_get($address, 'number'),
            'address_complement' => data_get($address, 'complement'),
            'district' => data_get($address, 'neighborhood') ?: data_get($address, 'district'),
            'payment_method' => $paymentMethod,
            'total_amount' => $totalAmount,
            'delivery_fee' => $deliveryFee,
            'discount_amount' => (float) (data_get($details, 'total.benefits') ?: 0),
            'status' => 'pending',
            'type' => 'sale',
            'fulfillment_type' => $fulfillmentType,
            'observation' => $this->buildObservation($details, $displayId, $orderType, $deliveredBy),
        ]);

        $items = (array) data_get($details, 'items', []);

        foreach ($items as $item) {
            $this->createOrderItem($order, $store, $item);
        }

        if ($order->items()->count() === 0) {
            $order->items()->create([
                'product_id' => null,
                'quantity' => 1,
                'price' => max(0, $totalAmount - $deliveryFee),
                'subtotal' => max(0, $totalAmount - $deliveryFee),
                'observation' => 'Pedido iFood #' . ($displayId ?: $ifoodOrderId),
                'options' => [],
            ]);
        }

        return $order;
    }

    private function createOrderItem(Order $order, Store $store, array $item): void
    {
        $externalId = data_get($item, 'id') ?: data_get($item, 'externalCode');
        $product = null;

        if (filled($externalId)) {
            $product = Product::query()
                ->where('store_id', $store->id)
                ->where('ifood_item_id', $externalId)
                ->first();
        }

        $quantity = max(1, (int) (data_get($item, 'quantity') ?: 1));
        $unitPrice = (float) (data_get($item, 'unitPrice') ?: data_get($item, 'price') ?: 0);
        $subtotal = (float) (data_get($item, 'totalPrice') ?: ($unitPrice * $quantity));

        $options = $this->extractItemOptions($item);

        $order->items()->create([
            'product_id' => $product?->id,
            'quantity' => $quantity,
            'price' => $unitPrice,
            'subtotal' => $subtotal,
            'observation' => data_get($item, 'observations') ?: data_get($item, 'name'),
            'options' => $options,
        ]);
    }

    /**
     * @return array<int, array{name: string, group_name: string, additional_price: float}>
     */
    private function extractItemOptions(array $item): array
    {
        $rawOptions = data_get($item, 'options', []);

        if (! is_array($rawOptions)) {
            return [];
        }

        $optionsList = array_is_list($rawOptions) ? $rawOptions : array_values($rawOptions);
        $normalized = [];

        foreach ($optionsList as $option) {
            if (! is_array($option)) {
                continue;
            }

            $normalized[] = [
                'name' => (string) (data_get($option, 'name') ?: 'Opção'),
                'group_name' => (string) (data_get($option, 'groupName')
                    ?: data_get($option, 'group_name')
                    ?: 'Adicionais'),
                'additional_price' => (float) (
                    data_get($option, 'unitPrice')
                    ?: data_get($option, 'price')
                    ?: data_get($option, 'addition')
                    ?: 0
                ),
            ];

            $customizations = data_get($option, 'customizations')
                ?? data_get($option, 'customization')
                ?? [];

            foreach ((array) $customizations as $customization) {
                if (! is_array($customization)) {
                    continue;
                }

                $normalized[] = [
                    'name' => (string) (data_get($customization, 'name') ?: 'Opção'),
                    'group_name' => (string) (data_get($customization, 'groupName')
                        ?: data_get($customization, 'group_name')
                        ?: data_get($option, 'groupName')
                        ?: 'Personalização'),
                    'additional_price' => (float) (
                        data_get($customization, 'unitPrice')
                        ?: data_get($customization, 'price')
                        ?: data_get($customization, 'addition')
                        ?: 0
                    ),
                ];
            }
        }

        return $normalized;
    }

    private function mapPaymentMethod(array $details): string
    {
        $methods = collect((array) data_get($details, 'payments.methods', []));
        $type = strtoupper((string) ($methods->first()['method'] ?? $methods->first()['type'] ?? ''));

        return match (true) {
            str_contains($type, 'PIX') => 'pix',
            str_contains($type, 'DEBIT') => 'debit_card',
            str_contains($type, 'CREDIT'), str_contains($type, 'CARD') => 'credit_card',
            str_contains($type, 'CASH'), str_contains($type, 'MONEY') => 'cash',
            default => 'pix',
        };
    }

    private function buildObservation(array $details, string $displayId, string $orderType, string $deliveredBy): string
    {
        $parts = array_filter([
            $displayId !== '' ? "iFood #{$displayId}" : null,
            "Tipo: {$orderType}",
            $deliveredBy !== '' ? "Entrega: {$deliveredBy}" : null,
            data_get($details, 'extraInfo'),
            data_get($details, 'delivery.observations'),
        ]);

        return implode(' · ', $parts);
    }
}
