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

        if (! $this->customerInitiatedWhatsappContact($store, $order)) {
            return false;
        }

        return filled($this->resolveCustomerPhone($order));
    }

    public function sendStatusUpdate(Order $order, string $status): bool
    {
        $order->loadMissing(['store.plan', 'user']);

        $store = $order->store;

        if (! $store || ! $this->canNotify($store, $order)) {
            if ($store && $store->canUseFeature('whatsapp_auto') && ! $this->customerInitiatedWhatsappContact($store, $order)) {
                Log::info('WhatsApp status skipped: customer has not messaged the store for this order', [
                    'order_id' => $order->id,
                    'store_id' => $store->id,
                ]);
            }

            return false;
        }

        $phone = $this->resolveCustomerPhone($order);

        if (blank($phone)) {
            return false;
        }

        $message = WhatsappOrderMessageTemplates::render($store, $order, $status);

        try {
            $this->messenger->sendText($store, $phone, $message);

            $order->forceFill(['sent_to_whatsapp_at' => now()])->save();

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

        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return strlen($digits) >= 10 ? $digits : null;
    }

    private function normalizeCustomerPhone(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        $normalized = $this->evolution->normalizePhonePublic($phone);

        return $normalized !== '' ? $normalized : null;
    }
}
