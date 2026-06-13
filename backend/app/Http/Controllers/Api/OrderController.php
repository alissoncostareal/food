<?php

namespace App\Http\Controllers\Api;

use App\Events\NewOrderPlaced;
use App\Events\OrderUpdated;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Models\Coupon;
use App\Models\DeliveryArea;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Services\IfoodOrderActions;
use App\Services\IfoodOrderSyncService;
use App\Services\OrderPixPaymentService;
use App\Services\OrderStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderController extends Controller
{
    use ResolvesMerchantStore;

    public function index(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'error' => 'Usuário não autenticado.',
                ], 401);
            }

            $validated = $request->validate([
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:5', 'max:50'],
                'status' => ['sometimes', 'string', 'in:all,pending,preparing,ready,shipped,delivered,canceled'],
            ]);

            $store = null;

            try {
                $store = $this->merchantStore();
            } catch (\Illuminate\Http\Exceptions\HttpResponseException) {
                $store = null;
            }

            $perPage = $validated['per_page'] ?? (int) config('orders.merchant_list_per_page', 15);
            $status = $validated['status'] ?? 'all';

            $query = Order::with(['items.product', 'deliveryArea', 'user', 'coupon'])
                ->latest();

            if ($store) {
                $query->where('store_id', $store->id);
            } else {
                $query->where('user_id', $user->id);
            }

            $query->forMerchantStatus($status);

            $paginator = $query->paginate($perPage);

            $meta = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ];

            if ($store) {
                $meta['counts'] = $this->orderStatusCounts($store->id);
            }

            return response()->json([
                'data' => $paginator->items(),
                'meta' => $meta,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar pedidos',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    private function orderStatusCounts(int $storeId): array
    {
        $base = Order::query()->where('store_id', $storeId);

        return [
            'all' => (clone $base)->count(),
            'pending' => (clone $base)->where('status', 'pending')->count(),
            'pending_actionable' => (clone $base)->actionablePending()->count(),
            'preparing' => (clone $base)->where('status', 'preparing')->count(),
            'ready' => (clone $base)->where('status', 'ready')->count(),
            'shipped' => (clone $base)->where('status', 'shipped')->count(),
            'delivered' => (clone $base)->where('status', 'delivered')->count(),
            'canceled' => (clone $base)->whereIn('status', ['canceled', 'cancelled'])->count(),
        ];
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
                    'options' => $options->isNotEmpty() ? $options->values()->all() : null,
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

            $isCustomerOwner = (int) $order->user_id === (int) $user->id;
            $isStoreOwner = false;

            if (! $isCustomerOwner) {
                try {
                    $merchantStore = $this->merchantStore();
                    $isStoreOwner = (int) $order->store_id === (int) $merchantStore->id;
                } catch (\Illuminate\Http\Exceptions\HttpResponseException) {
                    $isStoreOwner = false;
                }
            }

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

    public function updateStatus(Request $request, Order $order, IfoodOrderSyncService $ifoodSync, OrderStockService $stock)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,preparing,ready,shipped,delivered,canceled'],
            'ifood_cancellation_reason' => ['nullable', 'string', 'max:64'],
        ]);

        $merchantStore = $this->merchantStore();

        if ((int) $order->store_id !== (int) $merchantStore->id) {
            return response()->json([
                'error' => 'Não autorizado',
                'details' => 'Este pedido não pertence à sua loja.',
            ], 403);
        }

        if (
            $validated['status'] === 'preparing'
            && $order->status === 'pending'
            && $order->payment_status === OrderPixPaymentService::STATUS_AWAITING
        ) {
            return response()->json([
                'message' => 'Este pedido ainda aguarda confirmação do Pix.',
            ], 422);
        }

        if (
            $validated['status'] === 'preparing'
            && $order->status === 'pending'
            && ! $order->needs_attention
        ) {
            return response()->json([
                'message' => 'Este pedido expirou e não pode mais ser aceito.',
            ], 422);
        }

        if (
            $order->order_source === 'ifood'
            && $validated['status'] === 'canceled'
            && blank($validated['ifood_cancellation_reason'] ?? null)
        ) {
            return response()->json([
                'message' => 'Selecione o motivo de cancelamento exigido pelo iFood.',
                'requires_ifood_cancellation_reason' => true,
            ], 422);
        }

        try {
            $order->loadMissing('store');

            $previousStatus = $order->status;

            DB::transaction(function () use ($order, $validated, $ifoodSync) {
                $ifoodSync->syncLocalStatus(
                    $order,
                    $validated['status'],
                    $validated['ifood_cancellation_reason'] ?? null
                );

                $order->update([
                    'status' => $validated['status'],
                ]);
            });

            if ($validated['status'] === 'canceled' && $previousStatus !== 'canceled') {
                $stock->restoreIfNeeded($order->fresh());
            }

            $updatedOrder = $order->fresh(['items.product', 'user', 'deliveryArea', 'coupon', 'store']);

            event(new OrderUpdated($updatedOrder, $previousStatus));

            return response()->json([
                'message' => 'Pedido atualizado com sucesso!',
                'order' => $updatedOrder,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'requires_ifood_cancellation_reason' => true,
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao atualizar status',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function ifoodCancellationReasons(Order $order, IfoodOrderActions $ifoodActions)
    {
        $merchantStore = $this->merchantStore();

        if ((int) $order->store_id !== (int) $merchantStore->id) {
            return response()->json([
                'error' => 'Não autorizado',
            ], 403);
        }

        if ($order->order_source !== 'ifood' || blank($order->ifood_order_id)) {
            return response()->json([
                'message' => 'Este pedido não é do iFood.',
            ], 422);
        }

        try {
            $order->loadMissing('store');

            return response()->json([
                'reasons' => $ifoodActions->cancellationReasons($order->store, (string) $order->ifood_order_id),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao buscar motivos de cancelamento.',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function print(Order $order)
    {
        try {
            $store = $this->merchantStore();

            if ((int) $order->store_id !== (int) $store->id) {
                return response()->json([
                    'error' => 'Não autorizado',
                ], 403);
            }

            $order->load(['items.product', 'user', 'deliveryArea', 'coupon', 'store']);

            $couponCode = $order->coupon?->code ?? $order->coupon_code ?? null;

            $couponDescription = $order->coupon?->description ?? $order->coupon_description ?? null;

            $printData = [
                'store_name' => $store->name,
                'order_id' => $order->id,
                'display_code' => $order->display_code,
                'order_source' => $order->order_source,
                'ifood_display_id' => $order->ifood_display_id,
                'ifood_order_type' => $order->ifood_order_type,
                'ifood_delivered_by' => $order->ifood_delivered_by,
                'ifood_delivery_localizer' => $order->ifood_delivery_localizer,
                'customer' => [
                    'name' => $order->customer_name ?: $order->user?->name ?: 'Cliente',
                    'phone' => $order->customer_phone ?: $order->user?->phone ?: 'N/A',
                    'address' => $order->address,
                    'address_number' => $order->address_number,
                    'address_complement' => $order->address_complement,
                    'district' => $order->district ?? 'N/A',
                ],
                'items' => $order->items->map(fn ($item) => [
                    'name' => $this->orderItemDisplayName($item),
                    'qty' => $item->quantity,
                    'unit_price' => number_format((float) $item->price, 2, ',', '.'),
                    'subtotal' => number_format((float) $item->subtotal, 2, ',', '.'),
                    'observation' => $item->observation,
                    'options' => $item->options_list ?? $item->options ?? [],
                ]),
                'coupon' => [
                    'code' => $couponCode,
                    'description' => $couponDescription,
                ],
                'amounts' => [
                    'items_total' => number_format(
                        ((float) $order->total_amount - (float) $order->delivery_fee) + (float) $order->discount_amount,
                        2,
                        ',',
                        '.'
                    ),
                    'discount' => (float) $order->discount_amount > 0
                        ? number_format((float) $order->discount_amount, 2, ',', '.')
                        : null,
                    'delivery_fee' => number_format((float) $order->delivery_fee, 2, ',', '.'),
                    'total' => number_format((float) $order->total_amount, 2, ',', '.'),
                ],
                'fulfillment_type' => $order->fulfillment_type,
                'payment_method' => $order->payment_method,
                'observation' => $order->observation,
                'status_label' => $order->status_label,
                'date' => $order->created_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i'),
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

    private function orderItemDisplayName(\App\Models\OrderItem $item): string
    {
        $fromProduct = trim((string) ($item->product?->name ?? ''));

        if ($fromProduct !== '') {
            return $fromProduct;
        }

        $fromObservation = trim((string) ($item->observation ?? ''));

        if ($fromObservation !== '') {
            return $fromObservation;
        }

        return 'Item';
    }
}
