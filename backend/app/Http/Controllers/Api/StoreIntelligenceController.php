<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Models\Order;
use App\Services\StoreInsightService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StoreIntelligenceController extends Controller
{
    use ResolvesMerchantStore;

    public function __construct(
        private readonly StoreInsightService $storeInsightService
    ) {
    }

    public function show(Request $request)
    {
        try {
            $store = $this->merchantStore();

            $store->loadMissing('plan');

            if (!$store->canUseFeature('intelligence')) {
                return response()->json([
                    'error' => 'Forbidden',
                    'message' => 'Inteligência com IA disponível no plano Premium.',
                ], 403);
            }

            $forceRefresh = $request->boolean('refresh');

            $now = now(config('app.timezone', 'America/Fortaleza'))->toImmutable();
            $todayStart = $now->startOfDay();
            $todayEnd = $now->endOfDay();
            $startOfMonth = $now->startOfMonth();
            $sevenDaysAgo = $now->subDays(6)->startOfDay();
            $thirtyDaysAgo = $now->subDays(29)->startOfDay();

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

            $todayOrdersQuery = Order::where('store_id', $store->id)
                ->whereBetween('created_at', [$todayStart, $todayEnd])
                ->whereNotIn('status', $ignoredStatus);

            $monthlyOrdersQuery = Order::where('store_id', $store->id)
                ->where('created_at', '>=', $startOfMonth)
                ->whereNotIn('status', $ignoredStatus);

            $stats = [
                'today' => [
                    'revenue' => (float) (clone $todayOrdersQuery)->sum('total_amount'),
                    'sales_count' => (int) (clone $todayOrdersQuery)->count(),
                ],
                'pending_now' => (int) Order::where('store_id', $store->id)
                    ->actionablePending()
                    ->count(),
                'active_orders' => (int) Order::where('store_id', $store->id)
                    ->whereIn('status', $activeOrderStatus)
                    ->count(),
                'monthly_revenue' => (float) (clone $monthlyOrdersQuery)->sum('total_amount'),
                'monthly_orders_count' => (int) (clone $monthlyOrdersQuery)->count(),
                'average_ticket' => (float) round(
                    (clone $monthlyOrdersQuery)->count() > 0
                        ? (clone $monthlyOrdersQuery)->sum('total_amount') / (clone $monthlyOrdersQuery)->count()
                        : 0,
                    2
                ),
            ];

            $chartData = Order::where('store_id', $store->id)
                ->whereNotIn('status', $ignoredStatus)
                ->where('created_at', '>=', $sevenDaysAgo)
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('SUM(total_amount) as total')
                )
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date', 'ASC')
                ->get()
                ->map(fn ($item) => [
                    'date' => $item->date,
                    'total' => (float) $item->total,
                ]);

            $topProducts = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->where('orders.store_id', $store->id)
                ->whereNotIn('orders.status', $ignoredStatus)
                ->select(
                    'products.id',
                    'products.name',
                    DB::raw('SUM(order_items.quantity) as total_qty')
                )
                ->groupBy('products.id', 'products.name')
                ->orderByDesc('total_qty')
                ->limit(5)
                ->get()
                ->map(fn ($prod) => [
                    'name' => $prod->name,
                    'total_qty' => (int) $prod->total_qty,
                ]);

            $ordersByWeekday = Order::where('store_id', $store->id)
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

            $ordersByHour = Order::where('store_id', $store->id)
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

            $delayedOrders = Order::where('store_id', $store->id)
                ->whereIn('status', $activeOrderStatus)
                ->where('created_at', '<=', $now->subMinutes(45))
                ->count();

            $canceledOrders30d = Order::where('store_id', $store->id)
                ->whereIn('status', $ignoredStatus)
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->count();

            $revenueLast7Days = (float) $chartData->sum('total');
            $revenueTrend = $this->resolveRevenueTrend($chartData);

            $insightResult = $this->storeInsightService->generate(
                $store->id,
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
                ],
                $forceRefresh
            );

            return response()->json([
                'insights' => $insightResult['items'] ?? [],
                'meta' => $insightResult['meta'] ?? null,
                'summary' => [
                    'stats' => $stats,
                    'peak_weekday' => $ordersByWeekday->first(),
                    'peak_hour' => $ordersByHour->first(),
                    'delayed_orders' => (int) $delayedOrders,
                    'delay_threshold_minutes' => 45,
                    'canceled_orders_30d' => (int) $canceledOrders30d,
                    'revenue_last_7_days' => $revenueLast7Days,
                    'revenue_trend' => $revenueTrend,
                    'top_products' => $topProducts,
                    'sales_by_weekday' => $ordersByWeekday,
                    'sales_by_hour' => $ordersByHour,
                ],
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Internal Server Error',
                'message' => 'Ocorreu um erro ao gerar a inteligência da loja.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
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
