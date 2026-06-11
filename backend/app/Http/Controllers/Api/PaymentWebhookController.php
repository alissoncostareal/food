<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\StorePaymentProvider;
use App\Services\OrderPixPaymentService;
use App\Services\Payments\StorePixGatewayResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function handle(
        Request $request,
        string $provider,
        StorePixGatewayResolver $resolver,
        OrderPixPaymentService $payments
    ) {
        $eventType = (string) (
            $request->input('type')
            ?? $request->input('event')
            ?? $request->input('action')
            ?? $request->input('event_type')
            ?? ''
        );

        $payload = (array) (
            $request->input('data')
            ?? $request->input('charge')
            ?? $request->input('payment')
            ?? $request->all()
        );

        try {
            $gateway = $resolver->resolve(new StorePaymentProvider(['provider' => $provider]));
            $orderId = $gateway->handleWebhook($payload, $eventType);

            if (! $orderId) {
                return response()->json(['message' => 'Webhook ignorado.']);
            }

            $order = Order::find($orderId);

            if (! $order) {
                return response()->json(['message' => 'Pedido não encontrado.'], 404);
            }

            $normalized = strtolower($eventType);

            if (str_contains($normalized, 'paid') || in_array($eventType, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED', 'payment.updated'], true)) {
                $payments->markPaid($order, data_get($payload, 'id'));

                return response()->json(['ok' => true]);
            }

            if (str_contains($normalized, 'failed')) {
                $payments->markFailed($order);

                return response()->json(['ok' => true]);
            }

            if (str_contains($normalized, 'canceled') || str_contains($normalized, 'expired')) {
                $payments->markExpired($order);

                return response()->json(['ok' => true]);
            }

            return response()->json(['message' => 'Evento não tratado.']);
        } catch (\Throwable $e) {
            Log::warning('Payment webhook error', [
                'provider' => $provider,
                'event' => $eventType,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Erro ao processar webhook.'], 500);
        }
    }
}
