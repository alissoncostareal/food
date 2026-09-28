<?php

namespace App\Listeners\WhatsApp;

use App\Events\OrderUpdated;
use App\Jobs\SendOrderStatusWhatsapp;
use App\Kafka\Producers\KafkaEventProducer;

class NotifyCustomerOnOrderStatusChanged
{
    public function handle(OrderUpdated $event): void
    {
        $currentStatus = (string) $event->order->status;
        $previousStatus = $event->previousStatus;

        if ($previousStatus !== null && $previousStatus === $currentStatus) {
            return;
        }

        $orderId = (int) $event->order->id;
        $storeId = (int) $event->order->store_id;

        // 1. Tenta publicar no Kafka para processamento assíncrono e desacoplado
        $published = KafkaEventProducer::publishWhatsappOutbound($orderId, $currentStatus, $storeId);

        // 2. Fallback para a fila tradicional caso o Kafka esteja desabilitado
        if (! $published) {
            SendOrderStatusWhatsapp::dispatch($orderId, $currentStatus);
        }
    }
}
