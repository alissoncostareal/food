<?php

namespace App\Kafka\Consumers;

use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\Consumer;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Contracts\MessageConsumer;
use Throwable;

class OrderLifecycleKafkaHandler extends Consumer
{
    public function handle(ConsumerMessage $message, MessageConsumer $consumer): void
    {
        $this($message, $consumer);
    }

    public function __invoke(ConsumerMessage $message, MessageConsumer $consumer): void
    {
        $body = $message->getBody();
        $eventType = (string) ($body['event_type'] ?? 'unknown');
        $storeId = (int) ($body['store_id'] ?? 0);
        $orderData = (array) ($body['order'] ?? []);

        try {
            Log::info("Kafka Order Lifecycle Event processed [{$eventType}]", [
                'event_type' => $eventType,
                'store_id' => $storeId,
                'order_id' => $orderData['id'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::error('Kafka Order Lifecycle consumer error', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
