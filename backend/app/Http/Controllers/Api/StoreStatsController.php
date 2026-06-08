<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StoreStatsController extends Controller
{
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

            $now = now(config('app.timezone', 'America/Fortaleza'))->toImmutable();
            $todayStart = $now->startOfDay();
            $todayEnd = $now->endOfDay();
            $startOfMonth = $now->startOfMonth();
            $sevenDaysAgo = $now->subDays(6)->startOfDay();

            $activeRevenueStatus = ['pending', 'preparing', 'ready', 'shipped', 'delivered'];
            $pendingStatus = ['pending', 'preparing', 'ready', 'shipped'];
            $ignoredStatus = ['canceled', 'cancelled'];

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

            return response()->json([
                'stats' => $stats,
                'chart' => $chartData,
                'top_products' => $topProducts,
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'is_open' => (bool) $store->is_open,
                    'logo_url' => $store->logo_url ?? null,
                    'pending_count' => (int) $stats['pending_now'],
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
}
