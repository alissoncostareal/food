<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Food99WebhookEvent;
use App\Services\Food99Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class Food99WebhookController extends Controller
{
    public function __construct(
        private readonly Food99Service $food99
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if ($request->isMethod('GET')) {
            return $this->ack([
                'service' => 'partiumenu-food99-webhook',
                'webhook_url' => $this->food99->resolveWebhookUrl(),
            ]);
        }

        $rawBody = $request->getContent();
        $signature = $request->header('X-99Food-Signature')
            ?: $request->header('X-App-Signature')
            ?: $request->header('Sign')
            ?: $request->header('Authorization');

        if (! $this->food99->validateWebhookSignature($rawBody, $signature)) {
            return response()->json([
                'errno' => 401,
                'errmsg' => 'invalid_signature',
                'code' => 401,
                'msg' => 'Assinatura inválida.',
            ], 401);
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            $payload = $request->all();
        }

        $eventType = (string) (
            data_get($payload, 'type')
            ?: data_get($payload, 'event')
            ?: data_get($payload, 'eventType')
            ?: data_get($payload, 'action')
            ?: 'unknown'
        );

        $shopId = data_get($payload, 'shopId')
            ?: data_get($payload, 'shop_id')
            ?: data_get($payload, 'appShopId')
            ?: data_get($payload, 'app_shop_id')
            ?: data_get($payload, 'storeId')
            ?: data_get($payload, 'data.shopId');

        $orderId = data_get($payload, 'orderId')
            ?: data_get($payload, 'order_id')
            ?: data_get($payload, 'order.id')
            ?: data_get($payload, 'data.orderId')
            ?: data_get($payload, 'data.order_id');

        $eventId = (string) (
            data_get($payload, 'id')
            ?: data_get($payload, 'eventId')
            ?: data_get($payload, 'event_id')
            ?: data_get($payload, 'messageId')
            ?: ''
        );

        Log::info('99Food webhook recebido', [
            'event_id' => $eventId !== '' ? $eventId : null,
            'event_type' => $eventType,
            'shop_id' => $shopId,
            'order_id' => $orderId,
        ]);

        try {
            Food99WebhookEvent::query()->create([
                'event_id' => $eventId !== '' ? $eventId : null,
                'event_type' => $eventType,
                'shop_id' => $shopId ? (string) $shopId : null,
                'order_id' => $orderId ? (string) $orderId : null,
                'status' => 'received',
                'payload' => $payload,
            ]);
        } catch (Throwable $e) {
            Log::error('99Food webhook: falha ao persistir evento', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->ack([
            'received' => true,
            'event_type' => $eventType,
        ]);
    }

    private function ack(array $extra = []): JsonResponse
    {
        return response()->json([
            'errno' => 0,
            'errmsg' => 'success',
            'code' => 0,
            'msg' => 'success',
            ...$extra,
        ]);
    }
}
