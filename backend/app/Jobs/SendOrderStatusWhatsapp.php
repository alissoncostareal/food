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

        if ($order->status !== $this->status) {
            return;
        }

        $notifier->sendStatusUpdate($order, $this->status);
    }
}
