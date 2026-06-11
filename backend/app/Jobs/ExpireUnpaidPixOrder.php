<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderPixPaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExpireUnpaidPixOrder implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $orderId
    ) {}

    public function handle(OrderPixPaymentService $payments): void
    {
        $order = Order::find($this->orderId);

        if (! $order) {
            return;
        }

        if ($order->payment_status !== OrderPixPaymentService::STATUS_AWAITING) {
            return;
        }

        if ($order->payment_expires_at && now()->lt($order->payment_expires_at)) {
            return;
        }

        $payments->markExpired($order);
    }
}
