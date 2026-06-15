<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Models\Order;
use App\Services\ImageService;
use App\Services\StoreInsightService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StoreStatsController extends Controller
{
    use ResolvesMerchantStore;

    public function __construct(
        private readonly StoreInsightService $storeInsightService
    ) {
    }

    public function index()
    {
        try {
            $store = $this->merchantStore();

            $storeId = $store->id;
            $store->loadMissing('plan');
            $hasAdvancedDashboard = $store->canUseFeature('dashboard_advanced');
            $hasIntelligence = $store->canUseFeature('intelligence');

            $now = now(config('app.timezone', 'America/Fortaleza'))->toImmutable();
            $todayStart = $now->startOfDay();
            $todayEnd = $now->endOfDay();
            $startOfMonth = $now->startOfMonth();
            $sevenDaysAgo = $now->subDays(6)->startOfDay();
            $thirtyDaysAgo = $now->subDays(29)->startOfDay();

            $activeRevenueStatus = ['pending', 'preparing', 'ready', 'shipped', 'delivered'];
            $activeOrderStatus = ['pending', 'preparing', 'ready', 'shipped'];
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
                ->actionablePending()
                ->count();

            $activeOrders = Order::where('store_id', $storeId)
                ->whereIn('status', $activeOrderStatus)
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
                'active_orders' => (int) $activeOrders,

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
            $insightsMeta = [
                'source' => null,
                'generated_at' => null,
                'model' => null,
            ];

            if ($hasAdvancedDashboard || $hasIntelligence) {
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
                    ->whereIn('status', $activeOrderStatus)
                    ->where('created_at', '<=', $now->subMinutes(45))
                    ->count();

                $canceledOrders30d = Order::where('store_id', $storeId)
                    ->whereIn('status', $ignoredStatus)
                    ->where('created_at', '>=', $thirtyDaysAgo)
                    ->count();

                $revenueLast7Days = (float) $chartData->sum('total');
                $revenueTrend = $this->resolveRevenueTrend($chartData);

                if ($hasIntelligence) {
                    $insightResult = $this->storeInsightService->generate(
                        $storeId,
                        $stats,
                        $topProducts,
                        $ordersByWeekday,
                        $ordersByHour,
                        (int) $delayedOrders,
                        $store->name,
                        [
                            'store_is_open' => (bool) $store->is_open_now,
                            'canceled_orders_30d' => (int) $canceledOrders30d,
                            'revenue_last_7_days' => $revenueLast7Days,
                            'revenue_trend' => $revenueTrend,
                        ]
                    );

                    $insights = $insightResult['items'] ?? [];
                    $insightsMeta = $insightResult['meta'] ?? $insightsMeta;
                }
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
                'insights_meta' => $insightsMeta,
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'is_open' => (bool) $store->is_open_now,
                    'manual_is_open' => (bool) $store->is_open,
                    'open_outside_hours' => (bool) $store->open_outside_hours,
                    'within_scheduled_hours' => $store->isWithinScheduledHours(),
                    'opening_status' => $store->opening_status,
                    'logo_url' => ImageService::publicUrl($store->logo_url),
                    'pending_count' => (int) $stats['pending_now'],
                    'plan' => $store->plan ? [
                        'id' => $store->plan->id,
                        'name' => $store->plan->name,
                        'slug' => $store->plan->slug,
                    ] : null,
                    'has_premium_dashboard' => $hasAdvancedDashboard,
                    'has_intelligence' => $hasIntelligence,
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
                'details' => config('app.debug') ? $e->getMessage() : null,
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

    private function resolveRevenueTrend($chartData): string
    {
        $points = collect($chartData)->pluck('total')->map(fn ($value) => (float) $value)->values();

        if ($points->count() < 4) {
            return 'stable';
        }

        $mid = (int) floor($points->count() / 2);
        $firstHalf = $points->slice(0, $mid)->avg() ?: 0;
        $secondHalf = $points->slice($mid)->avg() ?: 0;

        if ($secondHalf > $firstHalf * 1.1) {
            return 'up';
        }

        if ($secondHalf < $firstHalf * 0.9) {
            return 'down';
        }

        return 'stable';
    }

}
