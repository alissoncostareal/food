<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewOrderPlaced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Order $order)
    {
        // Certifique-se de que o objeto já venha carregado com as relações
        // necessárias (items.product, user) antes de disparar o evento no controller.
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('store.' . $this->order->store_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.created';
    }

    public function broadcastWith(): array
    {
        $order = $this->order->loadMissing(['items.product', 'user', 'store']);

        return [
            'order' => [
                'id' => $order->id,
                'store_id' => $order->store_id,
                'status' => $order->status,
                'order_source' => $order->order_source,
                'display_number' => $order->display_number,
                'display_code' => $order->display_code,
                'ifood_display_id' => $order->ifood_display_id,
                'customer_name' => $order->customer_name ?: $order->user?->name ?: 'Cliente',
                'total_amount' => (float) $order->total_amount,
                'items' => $order->items,
                'created_at' => $order->created_at,
            ],
        ];
    }

}
