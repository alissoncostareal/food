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

        $expiresAt = $order->payment_expires_at;

        if (! $expiresAt) {
            $ttlMinutes = max(5, (int) config('payments.unpaid_order_ttl_minutes', 30));
            $expiresAt = $order->created_at?->copy()->addMinutes($ttlMinutes);
        }

        if ($expiresAt && now()->lt($expiresAt)) {
            self::dispatch($this->orderId)->delay($expiresAt->copy()->addSeconds(15));

            return;
        }

        $payments->markExpired($order);
    }
}
