<?php

namespace App\Http\Controllers\Api;

use App\Events\NewOrderPlaced;
use App\Events\OrderUpdated;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\DeliveryArea;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Notifications\OrderStatusUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index()
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'error' => 'Usuário não autenticado.',
                ], 401);
            }

            $store = $user?->store;

            $query = Order::with(['items.product', 'deliveryArea', 'user', 'coupon'])
                ->latest();

            if ($store) {
                $query->where('store_id', $store->id);
            } else {
                $query->where('user_id', $user->id);
            }

            $orders = $query->get();

            return response()->json($orders);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar pedidos',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'store_id' => ['required', 'exists:stores,id'],
            'delivery_area_id' => ['required', 'exists:delivery_areas,id'],
            'address' => ['required', 'string'],
            'type' => ['required', 'in:sale,rent'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.observation' => ['nullable', 'string', 'max:255'],
            'items.*.options' => ['nullable', 'array'],
            'coupon_id' => ['nullable', 'exists:coupons,id'],
        ]);

        $userId = Auth::id();

        if (!$userId) {
            return response()->json([
                'error' => 'Usuário não autenticado.',
            ], 401);
        }

        $lockKey = "order_lock_{$userId}_" . md5(json_encode($validated['items']) . $validated['store_id']);

        if (!cache()->add($lockKey, 'true', now()->addSeconds(10))) {
            return response()->json([
                'error' => 'Processando',
                'message' => 'Seu pedido já está sendo enviado.',
            ], 429);
        }

        try {
            $targetStore = Store::findOrFail($validated['store_id']);

            if (!$targetStore->is_open_now) {
                cache()->forget($lockKey);

                return response()->json([
                    'error' => 'Loja fechada',
                    'message' => 'Loja não aceitando pedidos.',
                ], 422);
            }

            DB::beginTransaction();

            $deliveryArea = DeliveryArea::where('id', $validated['delivery_area_id'])
                ->where('store_id', $validated['store_id'])
                ->firstOrFail();

            $totalItemsAmount = 0;
            $itemsToCreate = [];

            $products = Product::whereIn('id', collect($validated['items'])->pluck('product_id'))
                ->get()
                ->keyBy('id');

            foreach ($validated['items'] as $itemData) {
                $product = $products->get($itemData['product_id']);

                if (!$product || (int) $product->store_id !== (int) $validated['store_id'] || !$product->is_active) {
                    throw new \Exception('Produto indisponível.');
                }

                if ($product->manage_stock && $product->stock_quantity < $itemData['quantity']) {
                    throw new \Exception("Estoque insuficiente para {$product->name}.");
                }

                $options = collect($itemData['options'] ?? [])->map(fn ($option) => [
                    'name' => $option['name'],
                    'group_name' => $option['group_name'],
                    'additional_price' => (float) $option['additional_price'],
                ]);

                $unitPrice = (float) $product->price + $options->sum('additional_price');
                $subtotal = $unitPrice * (int) $itemData['quantity'];

                $totalItemsAmount += $subtotal;

                $itemsToCreate[] = [
                    'product_id' => $product->id,
                    'quantity' => (int) $itemData['quantity'],
                    'price' => $unitPrice,
                    'subtotal' => $subtotal,
                    'options' => $options->isNotEmpty() ? $options->toJson() : null,
                    'observation' => $itemData['observation'] ?? null,
                ];

                if ($product->manage_stock) {
                    $product->decrement('stock_quantity', $itemData['quantity']);
                }
            }

            $discount = 0;
            $couponId = null;
            $coupon = null;

            if (!empty($validated['coupon_id'])) {
                $coupon = Coupon::where('id', $validated['coupon_id'])
                    ->where('store_id', $validated['store_id'])
                    ->where('is_active', true)
                    ->first();

                if ($coupon && ($coupon->expires_at === null || now()->lessThan($coupon->expires_at))) {
                    $couponId = $coupon->id;

                    $discount = $coupon->type === 'percentage'
                        ? min(
                            $totalItemsAmount * ((float) $coupon->value / 100),
                            $coupon->max_discount_amount ?? $totalItemsAmount
                        )
                        : min((float) $coupon->value, $totalItemsAmount);

                    $coupon->increment('used_count');
                }
            }

            $order = Order::create([
                'user_id' => $userId,
                'store_id' => $validated['store_id'],
                'delivery_area_id' => $validated['delivery_area_id'],
                'coupon_id' => $couponId,
                'coupon_code' => $coupon?->code,
                'coupon_description' => $coupon?->description,
                'discount_amount' => $discount,
                'total_amount' => ($totalItemsAmount + $deliveryArea->fee) - $discount,
                'delivery_fee' => $deliveryArea->fee,
                'status' => 'pending',
                'type' => $validated['type'],
                'address' => $validated['address'],
            ]);

            $order->items()->createMany($itemsToCreate);

            DB::commit();

            cache()->forget($lockKey);

            $fullOrderData = $order->load(['items.product', 'user', 'deliveryArea', 'coupon']);

            event(new NewOrderPlaced($fullOrderData));

            return response()->json([
                'message' => 'Pedido enviado!',
                'order' => $fullOrderData,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            cache()->forget($lockKey);

            return response()->json([
                'error' => 'Falha ao processar pedido',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function show(Order $order)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'error' => 'Usuário não autenticado.',
                ], 401);
            }

            $store = $user->store;

            $isCustomerOwner = (int) $order->user_id === (int) $user->id;
            $isStoreOwner = $store && (int) $order->store_id === (int) $store->id;

            if (!$isCustomerOwner && !$isStoreOwner) {
                return response()->json([
                    'error' => 'Não autorizado',
                ], 403);
            }

            return response()->json([
                'data' => $order->load(['store', 'items.product', 'deliveryArea', 'user', 'coupon']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Pedido não encontrado',
                'details' => $e->getMessage(),
            ], 404);
        }
    }

    public function updateStatus(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,preparing,ready,shipped,delivered,canceled'],
        ]);

        $user = Auth::user();
        $merchantStore = $user?->store;

        if (!$merchantStore || (int) $order->store_id !== (int) $merchantStore->id) {
            return response()->json([
                'error' => 'Não autorizado',
                'details' => 'Este pedido não pertence à sua loja.',
            ], 403);
        }

        try {
            $order->update([
                'status' => $validated['status'],
            ]);

            if ($order->user) {
                $order->user->notify(new OrderStatusUpdated($order));
            }

            $updatedOrder = $order->fresh(['items.product', 'user', 'deliveryArea', 'coupon']);

            event(new OrderUpdated($updatedOrder));

            return response()->json([
                'message' => 'Pedido atualizado com sucesso!',
                'order' => $updatedOrder,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao atualizar status',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function print(Order $order)
    {
        try {
            $user = Auth::user();
            $store = $user?->store;

            if (!$store || (int) $order->store_id !== (int) $store->id) {
                return response()->json([
                    'error' => 'Não autorizado',
                ], 403);
            }

            $order->load(['items.product', 'user', 'deliveryArea', 'coupon']);

            $couponCode = $order->coupon?->code || $order->coupon_code
                ? ($order->coupon?->code ?? $order->coupon_code)
                : null;

            $couponDescription = $order->coupon?->description || $order->coupon_description
                ? ($order->coupon?->description ?? $order->coupon_description)
                : null;

            $printData = [
                'store_name' => $store->name,
                'order_id' => $order->id,
                'customer' => [
                    'name' => $order->user->name,
                    'phone' => $order->user->phone ?? 'N/A',
                    'address' => $order->address,
                    'district' => $order->district ?? 'N/A',
                ],
                'items' => $order->items->map(fn ($item) => [
                    'name' => $item->product->name,
                    'qty' => $item->quantity,
                    'unit_price' => number_format($item->price, 2, ',', '.'),
                    'subtotal' => number_format($item->subtotal, 2, ',', '.'),
                    'observation' => $item->observation,
                    'options' => is_string($item->options) ? json_decode($item->options, true) : $item->options,
                ]),
                'coupon' => [
                    'code' => $couponCode,
                    'description' => $couponDescription,
                ],
                'amounts' => [
                    'items_total' => number_format(
                        ($order->total_amount - $order->delivery_fee) + $order->discount_amount,
                        2,
                        ',',
                        '.'
                    ),
                    'discount' => $order->discount_amount > 0 ? number_format($order->discount_amount, 2, ',', '.') : null,
                    'delivery_fee' => number_format($order->delivery_fee, 2, ',', '.'),
                    'total' => number_format($order->total_amount, 2, ',', '.'),
                ],
                'status_label' => $order->status_label,
                'date' => $order->created_at->format('d/m/Y H:i'),
            ];

            return request()->wantsJson()
                ? response()->json($printData)
                : view('print.order', compact('order', 'printData'));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao processar impressão',
                'details' => $e->getMessage(),
            ], 400);
        }
    }
}
