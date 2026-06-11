<?php

namespace App\Listeners\WhatsApp;

use App\Events\NewOrderPlaced;
use App\Jobs\SendOrderStatusWhatsapp;

class NotifyCustomerOnNewOrder
{
    public function handle(NewOrderPlaced $event): void
    {
        $status = (string) $event->order->status;

        if ($status === '') {
            return;
        }

        SendOrderStatusWhatsapp::dispatch($event->order->id, $status);
    }
}
