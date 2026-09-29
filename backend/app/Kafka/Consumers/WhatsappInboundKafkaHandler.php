<?php

namespace App\Kafka\Consumers;

use App\Models\Store;
use App\Services\WhatsappInboundHandler;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\Consumer;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Contracts\MessageConsumer;
use Throwable;

class WhatsappInboundKafkaHandler extends Consumer
{
    public function __construct(
        private readonly WhatsappInboundHandler $inboundHandler,
    ) {}

    public function handle(ConsumerMessage $message, MessageConsumer $consumer): void
    {
        $this($message, $consumer);
    }

    public function __invoke(ConsumerMessage $message, MessageConsumer $consumer): void
    {
        $body = $message->getBody();
        $storeId = (int) ($body['store_id'] ?? 0);
        $phone = (string) ($body['phone'] ?? '');
        $text = (string) ($body['text'] ?? '');
        $payload = (array) ($body['payload'] ?? []);
        $event = (string) ($body['event'] ?? '');

        try {
            $store = Store::with('plan')->find($storeId);

            if (! $store) {
                Log::warning('Kafka WhatsApp consumer: Store not found', ['store_id' => $storeId]);
                return;
            }

            if ($phone !== '' && $text !== '') {
                $this->inboundHandler->handleInboundMessage($store, $phone, $text);
            } elseif (! empty($payload)) {
                $this->inboundHandler->handle($store, $payload, $event);
            }
        } catch (Throwable $e) {
            Log::error('Kafka WhatsApp consumer error', [
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
