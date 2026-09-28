<?php

namespace App\Listeners\WhatsApp;

use App\Events\NewOrderPlaced;
use App\Jobs\SendOrderStatusWhatsapp;
use App\Kafka\Producers\KafkaEventProducer;
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

        $orderId = (int) $order->id;
        $storeId = (int) $store->id;

        // 1. Tenta publicar no Kafka para processamento assíncrono e desacoplado
        $published = KafkaEventProducer::publishWhatsappOutbound($orderId, $status, $storeId);

        // 2. Fallback para a fila tradicional caso o Kafka esteja desabilitado
        if (! $published) {
            SendOrderStatusWhatsapp::dispatch($orderId, $status);
        }
    }
}
