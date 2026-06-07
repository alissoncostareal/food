<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order->loadMissing([
            'items.product',
            'user',
            'deliveryArea',
        ]);
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('store.' . $this->order->store_id);
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'order' => [
                'id' => $this->order->id,
                'store_id' => $this->order->store_id,
                'user_id' => $this->order->user_id,
                'customer_name' => $this->order->user->name ?? 'Cliente',
                'total_amount' => (float) $this->order->total_amount,
                'delivery_fee' => (float) $this->order->delivery_fee,
                'status' => $this->order->status,
                'status_label' => $this->order->status_label ?? ucfirst($this->order->status),
                'type' => $this->order->type,
                'address' => $this->order->address,
                'items_count' => $this->order->items->sum('quantity'),
                'created_at' => $this->order->created_at,
                'updated_at' => $this->order->updated_at,
            ],
        ];
    }
}
