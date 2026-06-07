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
                    'message' => 'Usuário não autenticado.'
                ], 401);
            }

            $store = $user->store;

            if (!$store) {
                return response()->json([
                    'error' => 'Not Found',
                    'message' => 'Loja não encontrada para este usuário.'
                ], 404);
            }

            $storeId = $store->id;

            $now = now()->toImmutable();
            $today = $now->startOfDay();
            $startOfMonth = $now->startOfMonth();
            $sevenDaysAgo = $now->subDays(6)->startOfDay();

            $successStatus = ['confirmed', 'completed', 'delivered', 'paid'];
            $pendingStatus = ['pending', 'preparing', 'ready'];

            $todayOrdersQuery = Order::where('store_id', $storeId)
                ->where('created_at', '>=', $today);

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
                        'customer_name' => $order->user->name ?? 'Cliente',
                        'total_amount' => (float) $order->total_amount,
                        'status' => $order->status,
                        'status_label' => $order->status_label ?? ucfirst($order->status),
                        'items_count' => (int) $order->items_count,
                        'created_at' => $order->created_at,
                        'updated_at' => $order->updated_at,
                    ];
                });

            $stats = [
                'today' => [
                    'revenue' => (float) (clone $todayOrdersQuery)
                        ->whereIn('status', $successStatus)
                        ->sum('total_amount'),

                    'sales_count' => (int) (clone $todayOrdersQuery)
                        ->where('status', '!=', 'canceled')
                        ->count(),
                ],

                'pending_now' => (int) $pendingNow,

                'monthly_revenue' => (float) Order::where('store_id', $storeId)
                    ->where('created_at', '>=', $startOfMonth)
                    ->whereIn('status', $successStatus)
                    ->sum('total_amount'),

                'recent_orders' => $recentOrders,
            ];

            $chartData = Order::where('store_id', $storeId)
                ->whereIn('status', $successStatus)
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
                        'total' => (float) $item->total
                    ];
                });

            $topProducts = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->join('products', 'order_items.product_id', '=', 'products.id')
                ->where('orders.store_id', $storeId)
                ->whereIn('orders.status', $successStatus)
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
                        'total_qty' => (int) $prod->total_qty
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
}
