<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Store;
use App\Models\StorePaymentProvider;
use App\Services\OrderPixPaymentService;
use App\Services\PagarMeService;
use App\Services\Payments\StorePixGatewayResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function handle(
        Request $request,
        string $provider,
        Store $store,
        PagarMeService $pagarMe,
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
            $metadataStoreId = (int) data_get($payload, 'metadata.store_id');

            if ($metadataStoreId > 0 && $metadataStoreId !== (int) $store->id) {
                return response()->json(['message' => 'Webhook não autorizado.'], 401);
            }

            $gateway = $resolver->resolve(new StorePaymentProvider(['provider' => $provider]));
            $orderId = $gateway->handleWebhook($payload, $eventType);

            if (! $orderId && $provider === 'mercadopago') {
                $paymentId = (string) (data_get($payload, 'id') ?? data_get($payload, 'data.id') ?? '');

                if ($paymentId !== '') {
                    $orderId = Order::query()
                        ->where('store_id', $store->id)
                        ->where('payment_provider', 'mercadopago')
                        ->where('payment_external_order_id', $paymentId)
                        ->value('id');
                }
            }

            if (! $orderId) {
                return response()->json(['message' => 'Webhook ignorado.']);
            }

            $order = Order::query()
                ->where('store_id', $store->id)
                ->find($orderId);

            if (! $order) {
                Log::warning('Payment webhook rejeitado: pedido não pertence à loja', [
                    'provider' => $provider,
                    'store_id' => $store->id,
                    'order_id' => $orderId,
                ]);

                return response()->json(['message' => 'Pedido não encontrado.'], 404);
            }

            if ($order->payment_provider && $order->payment_provider !== $provider) {
                Log::warning('Payment webhook rejeitado: provider divergente', [
                    'order_id' => $order->id,
                    'expected' => $order->payment_provider,
                    'received' => $provider,
                ]);

                return response()->json(['message' => 'Webhook não autorizado.'], 401);
            }

            if (in_array($provider, ['pagarme', 'asaas', 'mercadopago'], true)
                && ! $this->verifyStoreProviderWebhook($request, $provider, $store, $order, $payload, $pagarMe)) {
                Log::warning('Payment webhook rejeitado: verificação do provider falhou', [
                    'provider' => $provider,
                    'order_id' => $order->id,
                    'ip' => $request->ip(),
                ]);

                return response()->json(['message' => 'Webhook não autorizado.'], 401);
            }

            if ($payments->handleWebhookPayload($payload, $eventType)) {
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

    private function verifyStoreProviderWebhook(
        Request $request,
        string $provider,
        Store $store,
        Order $order,
        array $payload,
        PagarMeService $pagarMe
    ): bool {
        $connection = StorePaymentProvider::query()
            ->where('store_id', $store->id)
            ->where('provider', $provider)
            ->where('status', StorePaymentProvider::STATUS_CONNECTED)
            ->first();

        if (! $connection) {
            return ! app()->isProduction();
        }

        return match ($provider) {
            'pagarme' => $this->verifyPagarmeWebhook($request, $connection, $pagarMe),
            'asaas' => $this->verifyAsaasWebhook($request, $connection),
            'mercadopago' => $this->verifyMercadoPagoWebhook($connection, $order, $payload),
            default => false,
        };
    }

    private function verifyPagarmeWebhook(
        Request $request,
        StorePaymentProvider $connection,
        PagarMeService $pagarMe
    ): bool {
        $secret = (string) ($connection->credential('webhook_secret') ?: '');

        if ($secret === '') {
            return ! app()->isProduction();
        }

        return $pagarMe->verifyWebhookSignature(
            $request->getContent(),
            $request->header('x-hub-signature-256') ?? $request->header('x-hub-signature'),
            $secret
        );
    }

    private function verifyAsaasWebhook(Request $request, StorePaymentProvider $connection): bool
    {
        $apiKey = (string) $connection->credential('api_key');
        $token = (string) ($request->header('asaas-access-token') ?? '');

        if (blank($apiKey)) {
            return ! app()->isProduction();
        }

        if (blank($token)) {
            return false;
        }

        return hash_equals($apiKey, $token);
    }

    private function verifyMercadoPagoWebhook(
        StorePaymentProvider $connection,
        Order $order,
        array $payload
    ): bool {
        $token = (string) $connection->credential('access_token');
        $paymentId = (string) (
            data_get($payload, 'id')
            ?? data_get($payload, 'data.id')
            ?? $order->payment_external_order_id
            ?? ''
        );

        if (blank($token) || blank($paymentId)) {
            return ! app()->isProduction();
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(20)
            ->get('https://api.mercadopago.com/v1/payments/'.$paymentId);

        if ($response->failed()) {
            return false;
        }

        $body = $response->json();
        $metadata = (array) data_get($body, 'metadata', []);
        $metadataOrderId = (int) data_get($metadata, 'order_id');

        if (data_get($metadata, 'type') !== 'order_payment' || $metadataOrderId !== (int) $order->id) {
            return false;
        }

        if ($order->payment_external_order_id && $order->payment_external_order_id !== $paymentId) {
            return false;
        }

        return true;
    }
}
