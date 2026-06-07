<?php

namespace App\Http\Controllers\Api;

use App\Events\NewOrderPlaced;
use App\Events\OrderUpdated;
use App\Http\Controllers\Controller;
use App\Models\DeliveryArea;
use App\Models\Order;
use App\Models\Product;
use App\Notifications\NewOrderReceived;
use App\Notifications\OrderStatusUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class OrderController extends Controller
{
    /** @var \App\Models\User|null */
    protected $user;

    /** @var \App\Models\Store|null */
    protected $store;

    public function index()
    {
        try {
            $user = Auth::user();
            $store = $user->store;
            $orders = Order::where('user_id', $user->id)
                ->where('store_id', $store->id)
                ->with(['items.product', 'deliveryArea', 'user', 'coupon'])
                ->latest()
                ->get();

            return response()->json($orders);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao buscar pedidos', 'details' => $e->getMessage()], 400);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'store_id'              => 'required|exists:stores,id',
            'delivery_area_id'      => 'required|exists:delivery_areas,id',
            'address'               => 'required|string',
            'type'                  => 'required|in:sale,rent',
            'items'                 => 'required|array|min:1',
            'items.*.product_id'    => 'required|exists:products,id',
            'items.*.quantity'      => 'required|integer|min:1',
            'items.*.observation'   => 'nullable|string|max:255',
            'items.*.options'       => 'nullable|array',
            'coupon_id'             => 'nullable|exists:coupons,id',
        ]);

        $userId = Auth::id();
        $lockKey = "order_lock_{$userId}_" . md5(json_encode($request->items) . $request->store_id);

        if (!cache()->add($lockKey, 'true', now()->addSeconds(10))) {
            return response()->json(['error' => 'Processando', 'message' => 'Seu pedido já está sendo enviado.'], 429);
        }

        try {
            $targetStore = Store::findOrFail($request->store_id);
            if (!$targetStore->is_open_now) {
                cache()->forget($lockKey);
                return response()->json(['error' => 'Loja fechada', 'message' => 'Loja não aceitando pedidos.'], 422);
            }

            DB::beginTransaction();

            $deliveryArea = DeliveryArea::where('id', $request->delivery_area_id)
                ->where('store_id', $request->store_id)
                ->firstOrFail();

            $totalItemsAmount = 0;
            $itemsToCreate = [];
            $products = Product::whereIn('id', collect($request->items)->pluck('product_id'))->get()->keyBy('id');

            foreach ($request->items as $itemData) {
                $product = $products->get($itemData['product_id']);
                if (!$product || $product->store_id != $request->store_id || !$product->is_active) {
                    throw new \Exception("Produto indisponível.");
                }

                if ($product->manage_stock && $product->stock_quantity < $itemData['quantity']) {
                    throw new \Exception("Estoque insuficiente para {$product->name}.");
                }

                $options = collect($itemData['options'] ?? [])->map(fn($opt) => [
                    'name' => $opt['name'],
                    'group_name' => $opt['group_name'],
                    'additional_price' => (float) $opt['additional_price']
                ]);

                $unitPrice = $product->price + $options->sum('additional_price');
                $subtotal = $unitPrice * $itemData['quantity'];
                $totalItemsAmount += $subtotal;

                $itemsToCreate[] = [
                    'product_id'  => $product->id,
                    'quantity'    => $itemData['quantity'],
                    'price'       => $unitPrice,
                    'subtotal'    => $subtotal,
                    'options'     => $options->isNotEmpty() ? $options->toJson() : null,
                    'observation' => $itemData['observation'] ?? null,
                ];

                if ($product->manage_stock) $product->decrement('stock_quantity', $itemData['quantity']);
            }

            $discount = 0;
            $couponId = null;
            if ($request->coupon_id) {
                $coupon = \App\Models\Coupon::where('id', $request->coupon_id)
                    ->where('is_active', 1)
                    ->first();

                if ($coupon && ($coupon->expires_at === null || now()->lessThan($coupon->expires_at))) {
                    $couponId = $coupon->id;
                    $discount = ($coupon->type === 'percentage')
                        ? min($totalItemsAmount * ($coupon->value / 100), $coupon->max_discount_amount ?? $totalItemsAmount)
                        : min((float)$coupon->value, $totalItemsAmount);

                    $coupon->increment('used_count');
                }
            }

            $order = Order::create([
                'user_id'          => Auth::id(),
                'store_id'         => $request->store_id,
                'delivery_area_id' => $request->delivery_area_id,
                'coupon_id'        => $couponId,
                'discount_amount'  => $discount,
                'total_amount'     => ($totalItemsAmount + $deliveryArea->fee) - $discount,
                'delivery_fee'     => $deliveryArea->fee,
                'status'           => 'pending',
                'type'             => $request->type,
                'address'          => $request->address,
            ]);

            $order->items()->createMany($itemsToCreate);
            DB::commit();

            $fullOrderData = $order->load(['items.product', 'user', 'deliveryArea', 'coupon']);
            event(new NewOrderPlaced($fullOrderData));

            return response()->json(['message' => 'Pedido enviado!', 'order' => $fullOrderData], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            cache()->forget($lockKey);
            return response()->json(['error' => 'Falha ao processar pedido', 'details' => $e->getMessage()], 400);
        }
    }

    public function show(Order $order)
    {
        try {
            if ($order->user_id !== $this->user->id && $order->store_id !== $this->store?->id) {
                return response()->json(['error' => 'Não autorizado'], 403);
            }

            return $order->load(['store', 'items.product', 'deliveryArea', 'user', 'coupon']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Pedido não encontrado', 'details' => $e->getMessage()], 404);
        }
    }

    public function updateStatus(Request $request, Order $order)
    {
        $request->validate([
            'status' => 'required|in:pending,preparing,ready,shipped,delivered,canceled'
        ]);

        $user = auth()->user();
        $merchantStore = $user->store;

        if (!$merchantStore || $order->store_id !== $merchantStore->id) {
            return response()->json([
                'error' => 'Não autorizado',
                'details' => 'Este pedido não pertence à sua loja.'
            ], 403);
        }

        try {
            $order->update(['status' => $request->status]);

            $order->user->notify(new OrderStatusUpdated($order));

            $updatedOrder = $order->fresh(['items.product', 'user', 'deliveryArea']);

            event(new OrderUpdated($updatedOrder));

            return response()->json([
                'message' => 'Pedido atualizado com sucesso!',
                'order'   => $updatedOrder
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao atualizar status',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function print(Order $order)
    {
        try {

            $user = auth()->user();

            $store = $user->store;

            if (!$store || $order->store_id !== $store->id) {
                return response()->json(['error' => 'Não autorizado'], 403);
            }

            $order->load(['items.product', 'user', 'deliveryArea']);

            $printData = [
                'store_name' => $store->name,
                'order_id'   => $order->id,
                'customer'   => [
                    'name'     => $order->user->name,
                    'phone'    => $order->user->phone ?? 'N/A',
                    'address'  => $order->address,
                    'district' => $order->district ?? 'N/A',
                ],
                'items' => $order->items->map(fn($item) => [
                    'name'        => $item->product->name,
                    'qty'         => $item->quantity,
                    'unit_price'  => number_format($item->price, 2, ',', '.'),
                    'subtotal'    => number_format($item->subtotal, 2, ',', '.'),
                    'observation' => $item->observation,
                    'options'     => is_string($item->options) ? json_decode($item->options, true) : $item->options,
                ]),
                'amounts' => [
                    'items_total'  => number_format($order->total_amount - $order->delivery_fee, 2, ',', '.'),
                    'discount'     => $order->discount_amount > 0 ? number_format($order->discount_amount, 2, ',', '.') : null,
                    'delivery_fee' => number_format($order->delivery_fee, 2, ',', '.'),
                    'total'        => number_format($order->total_amount, 2, ',', '.'),
                ],
                'status_label' => $order->status_label,
                'date'         => $order->created_at->format('d/m/Y H:i'),
            ];

            return request()->wantsJson()
                ? response()->json($printData)
                : view('print.order', compact('order'));
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Erro ao processar impressão',
                'details' => $e->getMessage()
            ], 400);
        }
    }
}
