<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderWhatsappNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendOrderStatusWhatsapp implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $orderId,
        public string $status
    ) {}

    public function handle(OrderWhatsappNotifier $notifier): void
    {
        $order = Order::query()
            ->with(['store.plan', 'user'])
            ->find($this->orderId);

        if (! $order) {
            return;
        }

        $currentStatus = $this->normalizeStatus((string) $order->status);
        $expectedStatus = $this->normalizeStatus($this->status);

        if ($currentStatus !== $expectedStatus) {
            return;
        }

        $notifier->sendStatusUpdate($order, $expectedStatus);
    }

    private function normalizeStatus(string $status): string
    {
        return $status === 'cancelled' ? 'canceled' : $status;
    }
}
