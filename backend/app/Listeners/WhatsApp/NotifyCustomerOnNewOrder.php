<?php

namespace App\Listeners\WhatsApp;

use App\Events\NewOrderPlaced;
use App\Jobs\SendOrderStatusWhatsapp;
use App\Services\OrderWhatsappNotifier;

class NotifyCustomerOnNewOrder
{
    public function handle(NewOrderPlaced $event, OrderWhatsappNotifier $notifier): void
    {
        $order = $event->order->loadMissing(['store.plan', 'user']);
        $store = $order->store;

        if (! $store || ! $notifier->shouldNotifyOnNewOrder($store, $order)) {
            return;
        }

        $status = (string) $order->status;

        if ($status === '') {
            return;
        }

        SendOrderStatusWhatsapp::dispatchSync($order->id, $status);
    }
}
