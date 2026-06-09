<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\StoreInsightService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StoreStatsController extends Controller
{
    public function __construct(
        private readonly StoreInsightService $storeInsightService
    ) {
    }

    public function index()
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'error' => 'Unauthorized',
                    'message' => 'Usuário não autenticado.',
                ], 401);
            }

            $store = $user->store;

            if (!$store) {
                return response()->json([
                    'error' => 'Not Found',
                    'message' => 'Loja não encontrada para este usuário.',
                ], 404);
            }

            $storeId = $store->id;
            $store->loadMissing('plan');
            $isPremiumDashboard = $store->plan?->slug === 'premium';

            $now = now(config('app.timezone', 'America/Fortaleza'))->toImmutable();
            $todayStart = $now->startOfDay();
            $todayEnd = $now->endOfDay();
            $startOfMonth = $now->startOfMonth();
            $sevenDaysAgo = $now->subDays(6)->startOfDay();
            $thirtyDaysAgo = $now->subDays(29)->startOfDay();

            $activeRevenueStatus = ['pending', 'preparing', 'ready', 'shipped', 'delivered'];
            $pendingStatus = ['pending', 'preparing', 'ready', 'shipped'];
            $ignoredStatus = ['canceled', 'cancelled'];
            $driver = DB::connection()->getDriverName();
            $weekdayExpression = match ($driver) {
                'mysql', 'mariadb' => 'DAYOFWEEK(created_at)',
                'sqlite' => "(CAST(strftime('%w', created_at) AS INTEGER) + 1)",
                default => '(EXTRACT(DOW FROM created_at)::int + 1)',
            };
            $hourExpression = match ($driver) {
                'mysql', 'mariadb' => 'HOUR(created_at)',
                'sqlite' => "CAST(strftime('%H', created_at) AS INTEGER)",
                default => 'EXTRACT(HOUR FROM created_at)::int',
            };

            $todayOrdersQuery = Order::where('store_id', $storeId)
                ->whereBetween('created_at', [$todayStart, $todayEnd])
                ->whereNotIn('status', $ignoredStatus);

            $monthlyOrdersQuery = Order::where('store_id', $storeId)
                ->where('created_at', '>=', $startOfMonth)
                ->whereNotIn('status', $ignoredStatus);

            $pendingNow = Order::where('store_id', $storeId)
                ->whereIn('status', $pendingStatus)
                ->count();

            $recentOrders = Order::where('store_id', $storeId)
                ->with(['user:id,name'])
                ->withCount('items')
                ->latest()
                ->limit(5)
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'customer_name' => $order->user->name ?? $order->customer_name ?? 'Cliente',
                        'total_amount' => (float) $order->total_amount,
                        'status' => $order->status,
                        'status_label' => $this->getStatusLabel($order->status),
                        'items_count' => (int) $order->items_count,
                        'created_at' => $order->created_at,
                        'updated_at' => $order->updated_at,
                    ];
                });

            $stats = [
                'today' => [
                    'revenue' => (float) (clone $todayOrdersQuery)->sum('total_amount'),
                    'sales_count' => (int) (clone $todayOrdersQuery)->count(),
                ],

                'pending_now' => (int) $pendingNow,

                'monthly_revenue' => (float) (clone $monthlyOrdersQuery)->sum('total_amount'),
                'monthly_orders_count' => (int) (clone $monthlyOrdersQuery)->count(),
                'average_ticket' => (float) round(
                    (clone $monthlyOrdersQuery)->count() > 0
                        ? (clone $monthlyOrdersQuery)->sum('total_amount') / (clone $monthlyOrdersQuery)->count()
                        : 0,
                    2
                ),

                'recent_orders' => $recentOrders,
            ];

            $chartData = Order::where('store_id', $storeId)
                ->whereNotIn('status', $ignoredStatus)
                ->where('created_at', '>=', $sevenDaysAgo)
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('SUM(total_amount) as total')
                )
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date', 'ASC')
                ->get()
                ->map(function ($item) {
                    return [
                        'date' => $item->date,
                        'total' => (float) $item->total,
                    ];
                });

            $topProducts = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->where('orders.store_id', $storeId)
                ->whereNotIn('orders.status', $ignoredStatus)
                ->select(
                    'products.id',
                    'products.name',
                    DB::raw('SUM(order_items.quantity) as total_qty')
                )
                ->groupBy('products.id', 'products.name')
                ->orderByDesc('total_qty')
                ->limit(3)
                ->get()
                ->map(function ($prod) {
                    return [
                        'name' => $prod->name,
                        'total_qty' => (int) $prod->total_qty,
                    ];
                });

            $ordersByWeekday = collect();
            $ordersByHour = collect();
            $delayedOrders = 0;
            $insights = [];

            if ($isPremiumDashboard) {
                $ordersByWeekday = Order::where('store_id', $storeId)
                    ->whereNotIn('status', $ignoredStatus)
                    ->where('created_at', '>=', $thirtyDaysAgo)
                    ->select(
                        DB::raw("{$weekdayExpression} as weekday"),
                        DB::raw('COUNT(*) as orders_count'),
                        DB::raw('SUM(total_amount) as revenue')
                    )
                    ->groupBy(DB::raw($weekdayExpression))
                    ->orderByDesc('orders_count')
                    ->get()
                    ->map(fn ($item) => [
                        'weekday' => (int) $item->weekday,
                        'label' => $this->weekdayLabel((int) $item->weekday),
                        'orders_count' => (int) $item->orders_count,
                        'revenue' => (float) $item->revenue,
                    ]);

                $ordersByHour = Order::where('store_id', $storeId)
                    ->whereNotIn('status', $ignoredStatus)
                    ->where('created_at', '>=', $thirtyDaysAgo)
                    ->select(
                        DB::raw("{$hourExpression} as hour"),
                        DB::raw('COUNT(*) as orders_count'),
                        DB::raw('SUM(total_amount) as revenue')
                    )
                    ->groupBy(DB::raw($hourExpression))
                    ->orderByDesc('orders_count')
                    ->limit(5)
                    ->get()
                    ->map(fn ($item) => [
                        'hour' => (int) $item->hour,
                        'label' => str_pad((string) $item->hour, 2, '0', STR_PAD_LEFT) . ':00',
                        'orders_count' => (int) $item->orders_count,
                        'revenue' => (float) $item->revenue,
                    ]);

                $delayedOrders = Order::where('store_id', $storeId)
                    ->whereIn('status', $pendingStatus)
                    ->where('created_at', '<=', $now->subMinutes(45))
                    ->count();

                $insights = $this->storeInsightService->generate(
                    $stats,
                    $topProducts,
                    $ordersByWeekday,
                    $ordersByHour,
                    (int) $delayedOrders,
                    $store->name
                );
            }

            return response()->json([
                'stats' => $stats,
                'chart' => $chartData,
                'top_products' => $topProducts,
                'sales_by_weekday' => $ordersByWeekday,
                'sales_by_hour' => $ordersByHour,
                'operations' => [
                    'delayed_orders' => (int) $delayedOrders,
                    'delay_threshold_minutes' => 45,
                ],
                'insights' => $insights,
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'is_open' => (bool) $store->is_open_now,
                    'manual_is_open' => (bool) $store->is_open,
                    'logo_url' => $store->logo_url ?? null,
                    'pending_count' => (int) $stats['pending_now'],
                    'plan' => $store->plan ? [
                        'id' => $store->plan->id,
                        'name' => $store->plan->name,
                        'slug' => $store->plan->slug,
                    ] : null,
                    'has_premium_dashboard' => $isPremiumDashboard,
                ],
                'plan_limit' => [
                    'current_products' => $store->products()->count(),
                    'max_products' => $store->plan->max_products ?? 'Ilimitado',
                ],
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Internal Server Error',
                'message' => 'Ocorreu um erro ao gerar as estatísticas do painel.',
                'details' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    private function getStatusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => 'Pedido recebido',
            'preparing' => 'Em preparo',
            'ready' => 'Pronto para entrega',
            'shipped' => 'Saiu para entrega',
            'delivered' => 'Pedido entregue',
            'canceled', 'cancelled' => 'Pedido cancelado',
            default => 'Status desconhecido',
        };
    }

    private function weekdayLabel(int $weekday): string
    {
        return [
            1 => 'Domingo',
            2 => 'Segunda',
            3 => 'Terça',
            4 => 'Quarta',
            5 => 'Quinta',
            6 => 'Sexta',
            7 => 'Sábado',
        ][$weekday] ?? 'Dia';
    }

}
