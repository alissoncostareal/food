<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Auth;
use DB;
use Illuminate\Http\Request;

class StoreStatsController extends Controller
{
    public function index()
    {
        $store = Auth::user()->store;

        if (!$store) {
            return response()->json(['error' => 'Loja não encontrada'], 404);
        }

        // 1. Faturamento Total (Apenas pedidos confirmados ou completados)
        $totalRevenue = Order::where('store_id', $store->id)
            ->whereIn('status', ['confirmed', 'completed'])
            ->sum('total_amount');

        // 2. Total de Pedidos no mês atual
        $monthlyOrders = Order::where('store_id', $store->id)
            ->whereMonth('created_at', now()->month)
            ->count();

        // 3. Produtos mais vendidos/alugados
        $topProducts = DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->select('products.name', DB::raw('SUM(order_items.quantity) as total_sold'))
            ->where('products.store_id', $store->id)
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_sold')
            ->limit(5)
            ->get();

        // 4. Faturamento dos últimos 7 dias (para o gráfico no frontend)
        $chartData = Order::where('store_id', $store->id)
            ->whereIn('status', ['confirmed', 'completed'])
            ->where('created_at', '>=', now()->subDays(7))
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(total_amount) as total')
            )
            ->groupBy('date')
            ->get();

        return response()->json([
            'revenue' => $totalRevenue,
            'monthly_orders' => $monthlyOrders,
            'top_products' => $topProducts,
            'chart_data' => $chartData,
            'plan_limit' => [
                'current_products' => $store->products()->count(),
                'max_products' => $store->plan->max_products ?? 'Ilimitado'
            ]
        ]);
    }
}
