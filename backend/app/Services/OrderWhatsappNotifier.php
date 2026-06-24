<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use App\Models\WhatsappSession;
use App\Services\WhatsappProvisioningService;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderWhatsappNotifier
{
    public function __construct(
        private readonly StoreWhatsappMessenger $messenger,
        private readonly StoreWhatsappConnectionService $connection,
        private readonly EvolutionService $evolution,
    ) {}

    public function canNotify(Store $store, Order $order): bool
    {
        if (! $store->canUseFeature('whatsapp_auto')) {
            return false;
        }

        if (! $this->messenger->canSend($store)) {
            return false;
        }

        if (! $this->customerCanReceiveStatusUpdates($store, $order)) {
            return false;
        }

        $phone = $this->resolveCustomerPhone($order);

        if (blank($phone)) {
            return false;
        }

        return ! $this->isStoreOwnNumber($store, $phone);
    }

    public function shouldNotifyOnNewOrder(Store $store, Order $order): bool
    {
        if (! $store->canUseFeature('whatsapp_auto') || ! $this->messenger->canSend($store)) {
            return false;
        }

        $phone = $this->resolveCustomerPhone($order);

        if (blank($phone) || $this->isStoreOwnNumber($store, $phone)) {
            return false;
        }

        return $this->customerInitiatedWhatsappContact($store, $order);
    }

    public function sendStatusUpdate(Order $order, string $status): bool
    {
        $order->loadMissing(['store.plan', 'store.user', 'user']);

        $store = $order->store;

        if (! $store || ! $this->canNotify($store, $order)) {
            if ($store && $store->canUseFeature('whatsapp_auto')) {
                Log::info('WhatsApp status skipped', [
                    'order_id' => $order->id,
                    'store_id' => $store->id,
                    'status' => $status,
                    'order_source' => $order->order_source,
                    'connected' => $this->connection->isConnected($store),
                    'eligible' => $this->customerCanReceiveStatusUpdates($store, $order),
                    'customer_phone' => $order->customer_phone ?: $order->user?->phone,
                ]);
            }

            return false;
        }

        $phone = $this->resolveCustomerPhone($order);

        if (blank($phone) || $this->isStoreOwnNumber($store, $phone)) {
            Log::info('WhatsApp status skipped: invalid or store-owned recipient', [
                'order_id' => $order->id,
                'store_id' => $store->id,
                'status' => $status,
            ]);

            return false;
        }

        $message = WhatsappOrderMessageTemplates::render($store, $order, $status);
        $normalizedStatus = $this->normalizeStatus($status);

        if ($this->isDuplicateStatusNotification($order, $normalizedStatus)) {
            Log::info('WhatsApp status skipped: duplicate notification', [
                'order_id' => $order->id,
                'store_id' => $store->id,
                'status' => $normalizedStatus,
            ]);

            return false;
        }

        try {
            $this->messenger->sendText($store, $phone, $message);

            $order->forceFill([
                'sent_to_whatsapp_at' => now(),
                'whatsapp_last_status_sent' => $normalizedStatus,
            ])->save();

            Log::info('WhatsApp status notification sent', [
                'order_id' => $order->id,
                'store_id' => $store->id,
                'status' => $status,
                'phone' => $phone,
                'provider' => $store->whatsappProvider(),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('WhatsApp status notification failed', [
                'order_id' => $order->id,
                'store_id' => $store->id,
                'status' => $status,
                'phone' => $phone,
                'provider' => $store->whatsappProvider(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function customerCanReceiveStatusUpdates(Store $store, Order $order): bool
    {
        if ($this->customerOptedInViaWebCheckout($order)) {
            return true;
        }

        return $this->customerInitiatedWhatsappContact($store, $order);
    }

    private function customerOptedInViaWebCheckout(Order $order): bool
    {
        if (blank($this->resolveCustomerPhone($order))) {
            return false;
        }

        $source = strtolower((string) ($order->order_source ?? 'web'));

        if (in_array($source, ['ifood', 'demo_dashboard'], true)) {
            return false;
        }

        return in_array($source, ['web', 'customer', 'app', 'checkout'], true);
    }

    private function customerInitiatedWhatsappContact(Store $store, Order $order): bool
    {
        $phone = $this->normalizeCustomerPhone($this->resolveCustomerPhone($order));

        if (blank($phone)) {
            return false;
        }

        return WhatsappSession::query()
            ->where('store_id', $store->id)
            ->where('customer_phone', $phone)
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '>=', $order->created_at)
            ->exists();
    }

    private function resolveCustomerPhone(Order $order): ?string
    {
        $phone = $order->customer_phone ?: $order->user?->phone;

        if (blank($phone)) {
            return null;
        }

        $normalized = $this->normalizeCustomerPhone($phone);

        return $normalized !== null && strlen($normalized) >= 12 ? $normalized : null;
    }

    private function isStoreOwnNumber(Store $store, string $phone): bool
    {
        $recipient = $this->normalizeCustomerPhone($phone);

        if (blank($recipient)) {
            return false;
        }

        foreach ([
            $store->whatsapp_number,
            $store->whatsapp_phone,
            $store->phone,
            $store->user?->phone,
        ] as $candidate) {
            if (blank($candidate)) {
                continue;
            }

            if ($this->normalizeCustomerPhone($candidate) === $recipient) {
                return true;
            }
        }

        return false;
    }

    private function normalizeCustomerPhone(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        $normalized = $this->evolution->normalizePhonePublic($phone);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeStatus(string $status): string
    {
        return $status === 'cancelled' ? 'canceled' : $status;
    }

    private function isDuplicateStatusNotification(Order $order, string $normalizedStatus): bool
    {
        if (blank($order->whatsapp_last_status_sent)) {
            return false;
        }

        return $order->whatsapp_last_status_sent === $normalizedStatus
            && $this->normalizeStatus((string) $order->status) === $normalizedStatus;
    }
}
