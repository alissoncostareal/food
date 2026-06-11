<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use App\Services\WhatsappProvisioningService;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderWhatsappNotifier
{
    public function __construct(
        private readonly EvolutionService $evolution
    ) {}

    public function canNotify(Store $store, Order $order): bool
    {
        if (! $store->canUseFeature('whatsapp_auto')) {
            return false;
        }

        if (! $this->evolution->isConfigured()) {
            return false;
        }

        if ($store->evolution_status !== WhatsappProvisioningService::STATUS_CONNECTED
            && ! $this->evolution->isTestMode()) {
            return false;
        }

        return filled($this->resolveCustomerPhone($order));
    }

    public function sendStatusUpdate(Order $order, string $status): bool
    {
        $order->loadMissing(['store.plan', 'user']);

        $store = $order->store;

        if (! $store || ! $this->canNotify($store, $order)) {
            return false;
        }

        $phone = $this->resolveCustomerPhone($order);

        if (blank($phone)) {
            return false;
        }

        $message = WhatsappOrderMessageTemplates::render($store, $order, $status);

        try {
            $this->evolution->sendTextForStore($store, $phone, $message);

            $order->forceFill(['sent_to_whatsapp_at' => now()])->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('WhatsApp status notification failed', [
                'order_id' => $order->id,
                'store_id' => $store->id,
                'status' => $status,
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
}
