<?php

namespace App\Kafka\Producers;

use Illuminate\Support\Facades\Log;
use Junges\Kafka\Facades\Kafka;
use Junges\Kafka\Message\Message;
use Throwable;

class KafkaEventProducer
{
    public static function isEnabled(): bool
    {
        return (bool) config('kafka.enabled', env('KAFKA_ENABLED', false))
            && filled(config('kafka.brokers'));
    }

    /**
     * Publica mensagem recebida do WhatsApp (Evolution/Meta) para o tópico de entrada.
     */
    public static function publishWhatsappInbound(
        int $storeId,
        array $payload,
        string $event = '',
        ?string $phone = null,
        ?string $text = null
    ): bool {
        if (! static::isEnabled()) {
            return false;
        }

        try {
            $message = new Message(
                headers: [
                    'event' => 'whatsapp.inbound',
                    'published_at' => now()->toIso8601String(),
                ],
                body: [
                    'store_id' => $storeId,
                    'phone' => $phone,
                    'text' => $text,
                    'event' => $event,
                    'payload' => $payload,
                ],
                key: (string) $storeId
            );

            Kafka::publish()
                ->onTopic((string) config('kafka.topics.whatsapp_inbound', 'whatsapp.inbound.messages'))
                ->withMessage($message)
                ->send();

            return true;
        } catch (Throwable $e) {
            Log::warning('Failed to publish WhatsApp inbound message to Kafka', [
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Publica notificação de saída para o WhatsApp do cliente (mudança de status, novo pedido, etc).
     */
    public static function publishWhatsappOutbound(
        int $orderId,
        string $status,
        int $storeId,
        ?string $phone = null,
        ?string $customText = null
    ): bool {
        if (! static::isEnabled()) {
            return false;
        }

        try {
            $message = new Message(
                headers: [
                    'event' => 'whatsapp.outbound',
                    'order_id' => (string) $orderId,
                    'status' => $status,
                    'published_at' => now()->toIso8601String(),
                ],
                body: [
                    'order_id' => $orderId,
                    'status' => $status,
                    'store_id' => $storeId,
                    'phone' => $phone,
                    'text' => $customText,
                ],
                key: (string) $storeId
            );

            Kafka::publish()
                ->onTopic((string) config('kafka.topics.whatsapp_outbound', 'whatsapp.outbound.messages'))
                ->withMessage($message)
                ->send();

            return true;
        } catch (Throwable $e) {
            Log::warning('Failed to publish WhatsApp outbound status to Kafka', [
                'order_id' => $orderId,
                'status' => $status,
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Publica evento de ciclo de vida do pedido (criado, atualizado, pago, cancelado).
     */
    public static function publishOrderEvent(string $eventType, array $orderData, int $storeId): bool
    {
        if (! static::isEnabled()) {
            return false;
        }

        try {
            $message = new Message(
                headers: [
                    'event' => $eventType,
                    'published_at' => now()->toIso8601String(),
                ],
                body: [
                    'event_type' => $eventType,
                    'store_id' => $storeId,
                    'order' => $orderData,
                ],
                key: (string) $storeId
            );

            Kafka::publish()
                ->onTopic((string) config('kafka.topics.orders', 'orders.lifecycle.events'))
                ->withMessage($message)
                ->send();

            return true;
        } catch (Throwable $e) {
            Log::warning('Failed to publish Order event to Kafka', [
                'event_type' => $eventType,
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Publica evento recebido do iFood para processamento desacoplado.
     */
    public static function publishIfoodEvent(int $storeId, array $eventData): bool
    {
        if (! static::isEnabled()) {
            return false;
        }

        try {
            $message = new Message(
                headers: [
                    'event' => 'ifood.event',
                    'published_at' => now()->toIso8601String(),
                ],
                body: [
                    'store_id' => $storeId,
                    'event' => $eventData,
                ],
                key: (string) $storeId
            );

            Kafka::publish()
                ->onTopic((string) config('kafka.topics.ifood', 'ifood.incoming.events'))
                ->withMessage($message)
                ->send();

            return true;
        } catch (Throwable $e) {
            Log::warning('Failed to publish iFood event to Kafka', [
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
