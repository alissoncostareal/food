<?php

namespace App\Http\Controllers\Api;

use App\Events\NewOrderPlaced;
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

class OrderController extends Controller
{
    /** @var \App\Models\User|null */
    protected $user;

    /** @var \App\Models\Store|null */
    protected $store;

    public function __construct()
    {
        /** @var \App\Http\Controllers\Controller $this */
        $this->middleware(function ($request, $next) {
            // Usar a Facade explicitamente ajuda a IDE a não se perder
            $this->user = \Illuminate\Support\Facades\Auth::user();
            $this->store = $this->user?->store;

            return $next($request);
        });
    }

    public function index()
    {
        return Order::where('user_id', $this->user->id)
            ->with(['store', 'items.product'])
            ->latest()
            ->get();
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
            'items.*.options.*.additional_price' => 'nullable|numeric'
        ]);

        try {

            $targetStore = Store::findOrFail($request->store_id);

            // AGORA SIM: Checa botão manual + horário automático
            if (!$targetStore->is_open_now) {
                return response()->json([
                    'error' => 'Loja fechada',
                    'message' => 'Esta loja não está aceitando pedidos no momento.'
                ], 422);
            }

            DB::beginTransaction();

            $totalItemsAmount = 0;
            $itemsToCreate = [];

            // 1. Validar área de entrega
            $deliveryArea = DeliveryArea::where('id', $request->delivery_area_id)
                ->where('store_id', $request->store_id)
                ->firstOrFail();

            // 2. Processar Itens
            foreach ($request->items as $item) {
                $product = Product::where('id', $item['product_id'])
                    ->where('store_id', $request->store_id)
                    ->firstOrFail();

                // 1. Verificar se o produto está ativo
                if (!$product->is_active) {
                    throw new \Exception("O produto {$product->name} não está disponível.");
                }

                // 2. Verificar estoque (se o produto for controlado)
                if ($product->manage_stock) {
                    if ($product->stock_quantity < $item['quantity']) {
                        throw new \Exception("Estoque insuficiente para o produto {$product->name}.");
                    }

                    // 3. Decrementar o estoque (O Laravel faz isso fácil)
                    $product->decrement('stock_quantity', $item['quantity']);
                }

                // Soma adicionais usando collection para limpar o código
                $additionalPrice = collect($item['options'] ?? [])->sum('additional_price');

                $unitPrice = $product->price + $additionalPrice;
                $subtotal = $unitPrice * $item['quantity'];
                $totalItemsAmount += $subtotal;

                $itemsToCreate[] = [
                    'product_id'  => $product->id,
                    'quantity'    => $item['quantity'],
                    'price'       => $unitPrice,
                    'subtotal'    => $subtotal,
                    'observation' => $item['observation'] ?? null,
                    'options'     => isset($item['options']) ? json_encode($item['options']) : null,
                ];
            }

            // 3. Criar Pedido
            $order = Order::create([
                'user_id'          => $this->user->id,
                'store_id'         => $request->store_id,
                'delivery_area_id' => $request->delivery_area_id,
                'total_amount'     => $totalItemsAmount + $deliveryArea->fee,
                'delivery_fee'     => $deliveryArea->fee,
                'status'           => 'pending',
                'type'             => $request->type,
                'address'          => $request->address,
            ]);

            $order->items()->createMany($itemsToCreate);

            DB::commit();

            // 4. Notificações e Real-time
            $order->store->user->notify(new NewOrderReceived($order));
            broadcast(new NewOrderPlaced($order->load('items.product')))->toOthers();

            return response()->json([
                'message' => 'Pedido enviado para a cozinha!',
                'order'   => $order
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error'   => 'Falha ao processar pedido',
                'details' => $e->getMessage()
            ], 400);
        }
    }

    public function show(Order $order)
    {
        try {
            // Permite acesso se for o cliente ou o dono da loja
            if ($order->user_id !== $this->user->id && $order->store_id !== $this->store?->id) {
                return response()->json(['error' => 'Não autorizado'], 403);
            }

            return $order->load(['store', 'items.product', 'deliveryArea']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Pedido não encontrado', 'details' => $e->getMessage()], 404);
        }
    }

    public function updateStatus(Request $request, Order $order)
    {
        try {
            $request->validate([
                'status' => 'required|in:pending,preparing,ready,shipped,delivered,canceled'
            ]);

            if (!$this->store || $order->store_id !== $this->store->id) {
                return response()->json(['error' => 'Não autorizado'], 403);
            }

            $order->update(['status' => $request->status]);

            // Notificar o cliente
            $order->user->notify(new OrderStatusUpdated($order));

            return response()->json([
                'message' => "Pedido atualizado para {$order->status_label}!",
                'order'   => $order
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao atualizar status', 'details' => $e->getMessage()], 400);
        }
    }

    public function print(Order $order)
    {
        try {
            if (!$this->store || $order->store_id !== $this->store->id) {
                return response()->json(['error' => 'Não autorizado'], 403);
            }

            $order->load(['items.product', 'user', 'deliveryArea']);

            $printData = [
                'store_name' => $this->store->name,
                'order_id'   => $order->id,
                'customer'   => [
                    'name'     => $order->user->name,
                    'phone'    => $order->user->phone ?? 'N/A',
                    'address'  => $order->address,
                    'district' => $order->deliveryArea->district_name ?? 'N/A',
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
