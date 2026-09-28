<?php

namespace App\Kafka\Consumers;

use App\Models\Order;
use App\Models\Store;
use App\Services\OrderWhatsappNotifier;
use App\Services\StoreWhatsappMessenger;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Contracts\Handler;
use Junges\Kafka\Contracts\MessageConsumer;
use Throwable;

class WhatsappOutboundKafkaHandler implements Handler
{
    public function __construct(
        private readonly OrderWhatsappNotifier $orderNotifier,
        private readonly StoreWhatsappMessenger $messenger,
    ) {}

    public function __invoke(ConsumerMessage $message, MessageConsumer $consumer): void
    {
        $body = $message->getBody();
        $orderId = (int) ($body['order_id'] ?? 0);
        $status = (string) ($body['status'] ?? '');
        $storeId = (int) ($body['store_id'] ?? 0);
        $phone = (string) ($body['phone'] ?? '');
        $text = (string) ($body['text'] ?? '');

        try {
            if ($orderId > 0 && $status !== '') {
                $order = Order::query()
                    ->with(['store.plan', 'store.user', 'user'])
                    ->find($orderId);

                if (! $order) {
                    Log::warning('Kafka WhatsApp outbound consumer: Order not found', ['order_id' => $orderId]);
                    return;
                }

                $normalizedStatus = $status === 'cancelled' ? 'canceled' : $status;
                $sent = $this->orderNotifier->sendStatusUpdate($order, $normalizedStatus);

                Log::info('Kafka WhatsApp outbound status processed', [
                    'order_id' => $orderId,
                    'status' => $normalizedStatus,
                    'sent' => $sent,
                ]);

                return;
            }

            if ($storeId > 0 && $phone !== '' && $text !== '') {
                $store = Store::find($storeId);

                if ($store && $this->messenger->canSend($store)) {
                    $this->messenger->sendText($store, $phone, $text);

                    Log::info('Kafka WhatsApp direct message sent', [
                        'store_id' => $storeId,
                        'phone' => $phone,
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::error('Kafka WhatsApp outbound consumer error', [
                'order_id' => $orderId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
