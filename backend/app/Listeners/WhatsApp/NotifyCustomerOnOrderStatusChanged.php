<?php

namespace App\Listeners\WhatsApp;

use App\Events\OrderUpdated;
use App\Jobs\SendOrderStatusWhatsapp;

class NotifyCustomerOnOrderStatusChanged
{
    public function handle(OrderUpdated $event): void
    {
        $currentStatus = (string) $event->order->status;
        $previousStatus = $event->previousStatus;

        if ($previousStatus !== null && $previousStatus === $currentStatus) {
            return;
        }

        SendOrderStatusWhatsapp::dispatch($event->order->id, $currentStatus);
    }
}
