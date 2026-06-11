<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Models\Order;
use Illuminate\Http\Request;

class StoreOrderController extends Controller
{
    use ResolvesMerchantStore;

    public function show(Order $order)
    {
        $store = $this->merchantStore();

        if ((int) $order->store_id !== (int) $store->id) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        return response()->json(
            $order->load(['items.product', 'user', 'deliveryArea', 'coupon'])
        );
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:confirmed,canceled,completed'
        ]);

        $order = Order::findOrFail($id);

        if ($order->store_id !== $this->merchantStore()->id) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $order->update(['status' => $request->status]);

        return response()->json([
            'message' => "Pedido atualizado para {$request->status}!",
            'order' => $order
        ]);
    }

}
