<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\Order;
use App\Models\Store;
use App\Services\ImageService;
use Auth;
use DB;
use Gate;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    /** @var \App\Models\User */
        protected $user;

        /** @var \App\Models\Store */
        protected $store;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->user = auth()->user();
            /** @phpstan-ignore-next-line */ // Isso silencia erros de análise
            $this->store = $this->user?->store;
            return $next($request);
        });
    }

    public function index()
    {
        try {
            $stores = Store::where('subscription_ends_at', '>=', now())
                   ->orWhere('subscription_status', 'trial')
                   ->get();
            return StoreResource::collection($stores);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao buscar lojas', 'details' => $e->getMessage()], 400);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $store = Store::create($request->all());
            return new StoreResource($store);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao criar loja', 'details' => $e->getMessage()], 400);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $store = Store::findOrFail($id);
            return new StoreResource($store);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Loja não encontrada', 'details' => $e->getMessage()], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $store = Store::findOrFail($id);

            // O Laravel procura automaticamente a StorePolicy e chama o método 'update'
            Gate::authorize('update', $store);

            $store->update($request->all());
            return new StoreResource($store);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao atualizar loja', 'details' => $e->getMessage()], 400);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $store = Store::findOrFail($id);
            $store->delete();
            return response()->json(['message' => 'Loja removida com sucesso']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao remover loja', 'details' => $e->getMessage()], 400);
        }
    }

    public function showBySlug($slug)
    {
        try {
            $store = Store::where('slug', $slug)
            ->with(['productCategories.products' => function($query) {
                $query->where('is_active', true); // Só produtos ativos
            }])
            ->firstOrFail();

            // Verificamos se a loja pode operar (assinatura)
            $isExpired = $store->subscription_ends_at && now()->gt($store->subscription_ends_at);

            return response()->json([
                'store' => $store,
                'is_open' => $store->is_open && !$isExpired,
                'status_message' => $isExpired ? 'Assinatura pendente' : ($store->is_open ? 'Aberta' : 'Fechada')
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Loja não encontrada', 'details' => $e->getMessage()], 404);
        }
    }

    public function updateAppearance(Request $request)
    {
        $store = Auth::user()->store;

        $request->validate([
            'primary_color' => 'nullable|string|max:7',
            'description' => 'nullable|string|max:500',
            'banner' => 'nullable|image|max:2048',
        ]);

        $data = $request->only(['primary_color', 'description', 'instagram_link', 'whatsapp_number']);

        if ($request->hasFile('banner')) {
            // Usando o ImageService que comentamos antes
            $data['banner'] = ImageService::upload($request->file('banner'), 'banners');
        }

        $store->update($data);

        return response()->json(['message' => 'Identidade visual atualizada!', 'store' => $store]);
    }

    public function toggleOpen()
    {
        try {
            if (!$this->store instanceof Store) {
                return response()->json(['error' => 'Loja não configurada.'], 404);
            }

            $this->store->update([
                'is_open' => !$this->store->is_open
            ]);

            return response()->json([
                'message' => $this->store->is_open ? 'Loja aberta!' : 'Loja fechada!',
                'is_open' => (bool) $this->store->is_open
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao alterar status', 'details' => $e->getMessage()], 400);
        }
    }

    /**
     * Dashboard: Estatísticas da Loja
     */
    public function dashboard()
    {
        try {
            if (!$this->store) {
                return response()->json(['error' => 'Loja não vinculada'], 404);
            }

            $storeId = $this->store->id;
            $startOfMonth = now()->startOfMonth();
            $today = now()->startOfDay();
            $lastSevenDays = now()->subDays(6)->startOfDay();

            // 1. Query base para evitar repetição de filtros comuns
            $baseQuery = Order::where('store_id', $storeId);

            // 2. Gráfico de Vendas (Últimos 7 dias) - Agrupado via SQL para performance
            $chartData = Order::where('store_id', $storeId)
                ->where('created_at', '>=', $lastSevenDays)
                ->where('status', 'delivered')
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('SUM(total_amount) as total')
                )
                ->groupBy('date')
                ->orderBy('date', 'ASC')
                ->get();

            // 3. Consolidação das Estatísticas
            $stats = [
                'today' => [
                    'sales_count' => (clone $baseQuery)->where('created_at', '>=', $today)
                        ->where('status', '!=', 'canceled')
                        ->count(),
                    'revenue' => (clone $baseQuery)->where('created_at', '>=', $today)
                        ->where('status', 'delivered')
                        ->sum('total_amount'),
                ],
                'pending_now' => (clone $baseQuery)->whereIn('status', ['pending', 'preparing', 'ready'])
                    ->count(),
                'monthly_revenue' => (clone $baseQuery)->where('created_at', '>=', $startOfMonth)
                    ->where('status', 'delivered')
                    ->sum('total_amount'),
            ];

            // 4. Top Produtos (Diferencial para o lojista)
            $topProducts = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->where('orders.store_id', $storeId)
                ->where('orders.status', 'delivered')
                ->select('products.name', DB::raw('SUM(order_items.quantity) as total_qty'))
                ->groupBy('products.id', 'products.name')
                ->orderBy('total_qty', 'DESC')
                ->limit(3)
                ->get();

            return response()->json([
                'store' => $this->store->only(['name', 'is_open']),
                'stats' => $stats,
                'chart' => $chartData,
                'top_products' => $topProducts
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Erro ao processar métricas do dashboard',
                'details' => $e->getMessage()
            ], 400);
        }
    }

    public function updateOperatingHours(Request $request)
    {
        $request->validate([
            'hours' => 'required|array|size:7',
            'hours.*.day_of_week' => 'required|integer|between:0,6',
            'hours.*.opening_time' => 'required|date_format:H:i',
            'hours.*.closing_time' => 'required|date_format:H:i',
            'hours.*.is_closed' => 'required|boolean',
        ]);

        try {
            DB::transaction(function () use ($request) {
                foreach ($request->hours as $hour) {
                    // Atualiza se existir o dia, ou cria um novo
                    $this->store->operatingHours()->updateOrCreate(
                        ['day_of_week' => $hour['day_of_week']],
                        [
                            'opening_time' => $hour['opening_time'],
                            'closing_time' => $hour['closing_time'],
                            'is_closed'    => $hour['is_closed'],
                        ]
                    );
                }
            });

            return response()->json(['message' => 'Horários de funcionamento atualizados!']);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao salvar horários', 'details' => $e->getMessage()], 400);
        }
    }
}
