<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Auth;
use Illuminate\Http\Request;

class StoreOrderController extends Controller
{
    public function index()
    {
        $store = Auth::user()->store;

        if (!$store) {
            return response()->json(['error' => 'Você não possui uma loja.'], 404);
        }

        $orders = Order::where('store_id', $store->id)
            ->with(['items.product', 'user'])
            ->latest()
            ->paginate(10);

        return response()->json($orders);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:confirmed,canceled,completed'
        ]);

        $order = Order::findOrFail($id);

        if ($order->store_id !== Auth::user()->store->id) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $order->update(['status' => $request->status]);

        return response()->json([
            'message' => "Pedido atualizado para {$request->status}!",
            'order' => $order
        ]);
    }

}
