<?php

namespace App\Events;

use App\Models\Order;
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
            'coupon',
        ]);
    }

    public function broadcastOn(): PrivateChannel
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
                'customer_phone' => $this->order->user->phone ?? null,
                'total_amount' => (float) $this->order->total_amount,
                'delivery_fee' => (float) $this->order->delivery_fee,
                'discount_amount' => (float) ($this->order->discount_amount ?? 0),
                'status' => $this->order->status,
                'status_label' => $this->order->status_label ?? ucfirst($this->order->status),
                'type' => $this->order->type,
                'address' => $this->order->address,
                'delivery_address' => $this->order->address,
                'delivery_area' => $this->order->deliveryArea,
                'coupon' => $this->order->coupon,
                'coupon_code' => $this->order->coupon_code,
                'coupon_description' => $this->order->coupon_description,
                'items' => $this->order->items,
                'items_count' => $this->order->items->sum('quantity'),
                'created_at' => $this->order->created_at,
                'updated_at' => $this->order->updated_at,
            ],
        ];
    }
}
