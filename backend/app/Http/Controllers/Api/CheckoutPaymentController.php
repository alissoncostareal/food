<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderPixPaymentService;
use App\Services\WhatsappOrderUrlService;
use App\Support\BrazilPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckoutPaymentController extends Controller
{
    public function show(Request $request, Order $order, OrderPixPaymentService $payments, WhatsappOrderUrlService $whatsappUrls)
    {
        $phone = BrazilPhone::digits((string) $request->query('phone', ''));

        if (! $payments->verifyCustomerAccess($order, $phone)) {
            return response()->json([
                'message' => 'Pedido não encontrado.',
            ], 404);
        }

        if (in_array($order->payment_status, [
            OrderPixPaymentService::STATUS_AWAITING,
            OrderPixPaymentService::STATUS_EXPIRED,
        ], true)) {
            try {
                $payments->syncRemoteStatus($order);
                $order->refresh();
            } catch (\Throwable $e) {
                Log::warning('Checkout payment sync failed', [
                    'order_id' => $order->id,
                    'payment_status' => $order->payment_status,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $whatsappUrl = null;

        if ($order->payment_status === OrderPixPaymentService::STATUS_PAID) {
            $whatsappUrl = $whatsappUrls->ensureStoredForOrder($order->fresh(['store', 'items.product', 'user', 'deliveryArea', 'coupon']));
        }

        return response()->json([
            'order_id' => $order->id,
            'order_status' => $order->status,
            'payment' => $payments->paymentPayload($order),
            'whatsapp_url' => $whatsappUrl,
            'order' => $order->fresh(['store', 'items.product', 'user', 'deliveryArea', 'coupon']),
        ]);
    }

    public function regeneratePix(Request $request, Order $order, OrderPixPaymentService $payments)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $phone = BrazilPhone::digits((string) $validated['phone']);

        if (! $payments->verifyCustomerAccess($order, $phone)) {
            return response()->json([
                'message' => 'Pedido não encontrado.',
            ], 404);
        }

        try {
            $payment = $payments->regeneratePixCharge($order);
            $order->refresh();

            return response()->json([
                'message' => 'Novo Pix gerado com sucesso.',
                'order_id' => $order->id,
                'payment' => $payment,
                'order' => $order->fresh(['store', 'items.product', 'user', 'deliveryArea', 'coupon']),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Não foi possível gerar um novo Pix.',
            ], 422);
        }
    }
}
