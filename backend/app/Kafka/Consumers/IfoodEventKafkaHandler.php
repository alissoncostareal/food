<?php

namespace App\Kafka\Consumers;

use App\Models\Store;
use App\Services\IfoodOrderHandler;
use App\Services\IfoodService;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\Consumer;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Contracts\MessageConsumer;
use Throwable;

class IfoodEventKafkaHandler extends Consumer
{
    public function __construct(
        private readonly IfoodOrderHandler $orderHandler,
        private readonly IfoodService $ifoodService,
    ) {}

    public function handle(ConsumerMessage $message, MessageConsumer $consumer): void
    {
        $this($message, $consumer);
    }

    public function __invoke(ConsumerMessage $message, MessageConsumer $consumer): void
    {
        $body = $message->getBody();
        $storeId = (int) ($body['store_id'] ?? 0);
        $event = (array) ($body['event'] ?? []);

        if ($storeId <= 0 || empty($event)) {
            return;
        }

        try {
            $store = Store::find($storeId);

            if (! $store) {
                Log::warning('Kafka iFood consumer: Store not found', ['store_id' => $storeId]);
                return;
            }

            $this->orderHandler->handle($store, $event);

            $eventId = (string) data_get($event, 'id');

            if ($eventId !== '') {
                $this->ifoodService->acknowledgeEvents($store, [$eventId]);
            }

            Log::info('Kafka iFood event processed successfully', [
                'store_id' => $storeId,
                'event_id' => $eventId,
                'code' => data_get($event, 'code'),
            ]);
        } catch (Throwable $e) {
            Log::error('Kafka iFood consumer error', [
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
