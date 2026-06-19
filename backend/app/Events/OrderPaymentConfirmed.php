<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderPaymentConfirmed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->order = $order->loadMissing(['store']);
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('order-payment.'.$this->order->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'payment.confirmed';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'payment_status' => $this->order->payment_status,
            'order_status' => $this->order->status,
        ];
    }
}
