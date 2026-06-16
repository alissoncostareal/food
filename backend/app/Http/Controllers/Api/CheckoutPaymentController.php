<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderPixPaymentService;
use App\Services\WhatsappOrderUrlService;
use Illuminate\Http\Request;

class CheckoutPaymentController extends Controller
{
    public function show(Request $request, Order $order, OrderPixPaymentService $payments, WhatsappOrderUrlService $whatsappUrls)
    {
        $phone = preg_replace('/\D+/', '', (string) $request->query('phone', '')) ?? '';

        if (! $payments->verifyCustomerAccess($order, $phone)) {
            return response()->json([
                'message' => 'Pedido não encontrado.',
            ], 404);
        }

        if ($order->payment_status === OrderPixPaymentService::STATUS_AWAITING) {
            try {
                $payments->syncRemoteStatus($order);
                $order->refresh();
            } catch (\Throwable) {
                // Polling continua com status local se consulta remota falhar.
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
}
