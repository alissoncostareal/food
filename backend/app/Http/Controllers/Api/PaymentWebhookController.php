<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Store;
use App\Models\StorePaymentProvider;
use App\Services\OrderPixPaymentService;
use App\Services\PagarMeService;
use App\Services\Payments\StorePixGatewayResolver;
use Illuminate\Http\JsonResponse;
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
        $payload = $this->normalizeWebhookPayload($request, $provider);
        $eventType = (string) (
            $request->input('type')
            ?? $request->input('event')
            ?? $request->input('action')
            ?? $request->input('event_type')
            ?? $request->query('topic')
            ?? data_get($payload, 'type')
            ?? data_get($payload, 'topic')
            ?? ''
        );

        try {
            $metadataStoreId = (int) data_get($payload, 'metadata.store_id');

            if ($metadataStoreId > 0 && $metadataStoreId !== (int) $store->id) {
                return response()->json(['message' => 'Webhook não autorizado.'], 401);
            }

            $gateway = $resolver->resolve(new StorePaymentProvider(['provider' => $provider]));
            $orderId = $gateway->handleWebhook($payload, $eventType);

            if (! $orderId && $provider === 'mercadopago') {
                $paymentId = $this->resolveMercadoPagoPaymentId($payload, $request);

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

            if ($provider === 'mercadopago') {
                return $this->handleMercadoPagoWebhook($store, $order, $payload, $request, $payments);
            }

            if (in_array($provider, ['pagarme', 'asaas'], true)
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

    private function handleMercadoPagoWebhook(
        Store $store,
        Order $order,
        array $payload,
        Request $request,
        OrderPixPaymentService $payments
    ): JsonResponse {
        $connection = StorePaymentProvider::query()
            ->where('store_id', $store->id)
            ->where('provider', 'mercadopago')
            ->where('status', StorePaymentProvider::STATUS_CONNECTED)
            ->first();

        if (! $connection) {
            if (! app()->isProduction()) {
                $payments->syncRemoteStatus($order);
                $order->refresh();

                return response()->json([
                    'ok' => true,
                    'payment_status' => $order->payment_status,
                ]);
            }

            return response()->json(['message' => 'Webhook não autorizado.'], 401);
        }

        $paymentId = $this->resolveMercadoPagoPaymentId($payload, $request, $order);
        $paymentBody = $this->fetchMercadoPagoPayment($connection, $paymentId);

        if (! $paymentBody || ! $this->mercadoPagoPaymentBelongsToOrder($paymentBody, $order, $paymentId)) {
            Log::warning('Payment webhook rejeitado: pagamento MP inválido', [
                'order_id' => $order->id,
                'payment_id' => $paymentId,
            ]);

            return response()->json(['message' => 'Webhook não autorizado.'], 401);
        }

        $payments->applyMercadoPagoPayment($order, $paymentBody);
        $order->refresh();

        return response()->json([
            'ok' => true,
            'payment_status' => $order->payment_status,
        ]);
    }

    private function normalizeWebhookPayload(Request $request, string $provider): array
    {
        if ($provider === 'mercadopago' && $request->isMethod('GET')) {
            return array_filter([
                'id' => $request->query('id') ?? $request->query('data_id'),
                'topic' => $request->query('topic'),
                'type' => $request->query('topic'),
            ], fn ($value) => filled($value));
        }

        return (array) (
            $request->input('data')
            ?? $request->input('charge')
            ?? $request->input('payment')
            ?? $request->all()
        );
    }

    private function resolveMercadoPagoPaymentId(array $payload, Request $request, ?Order $order = null): string
    {
        return (string) (
            data_get($payload, 'id')
            ?? $request->input('data.id')
            ?? data_get($payload, 'data.id')
            ?? $order?->payment_external_order_id
            ?? ''
        );
    }

    private function fetchMercadoPagoPayment(StorePaymentProvider $connection, string $paymentId): ?array
    {
        $token = (string) $connection->credential('access_token');

        if (blank($token) || blank($paymentId)) {
            return null;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(10)
            ->get('https://api.mercadopago.com/v1/payments/'.$paymentId);

        if ($response->failed()) {
            return null;
        }

        return $response->json();
    }

    private function mercadoPagoPaymentBelongsToOrder(array $paymentBody, Order $order, string $paymentId): bool
    {
        $metadata = (array) data_get($paymentBody, 'metadata', []);
        $metadataOrderId = (int) data_get($metadata, 'order_id');

        if (data_get($metadata, 'type') !== 'order_payment' || $metadataOrderId !== (int) $order->id) {
            return false;
        }

        if ($order->payment_external_order_id && $order->payment_external_order_id !== $paymentId) {
            return false;
        }

        return true;
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
}
