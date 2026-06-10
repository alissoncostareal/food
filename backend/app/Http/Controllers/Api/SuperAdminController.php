<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class SuperAdminController extends Controller
{
    public function summary()
    {
        try {
            $now = now();
            $monthStart = $now->copy()->startOfMonth();
            $last30Days = $now->copy()->subDays(30);
            $ignoredStatus = ['canceled', 'cancelled'];

            $stores = Store::query()
                ->with(['user:id,name,email,phone,role', 'plan'])
                ->withCount('orders')
                ->get();

            $totalStores = $stores->count();
            $activeStores = $stores->whereIn('subscription_status', ['active', 'trial', 'complimentary'])->count();
            $trialStores = $stores->where('subscription_status', 'trial')->count();
            $complimentaryStores = $stores->where('subscription_status', 'complimentary')->count();
            $attentionStores = $stores->whereIn('subscription_status', ['past_due', 'canceled', 'suspended', 'expired_trial'])->count();

            $estimatedMrr = $stores
                ->where('subscription_status', 'active')
                ->sum(fn (Store $store) => (float) ($store->plan?->price ?? 0));

            $monthRevenue = (float) Order::query()
                ->whereNotIn('status', $ignoredStatus)
                ->where('created_at', '>=', $monthStart)
                ->sum('total_amount');

            $monthOrders = Order::query()
                ->whereNotIn('status', $ignoredStatus)
                ->where('created_at', '>=', $monthStart)
                ->count();

            $last30Orders = Order::query()
                ->whereNotIn('status', $ignoredStatus)
                ->where('created_at', '>=', $last30Days)
                ->count();

            $statusDistribution = $stores
                ->groupBy(fn (Store $store) => $store->subscription_status ?: 'unknown')
                ->map(fn ($items, $status) => [
                    'status' => $status,
                    'label' => $this->subscriptionStatusLabel((string) $status),
                    'count' => $items->count(),
                ])
                ->values();

            $plans = Plan::query()
                ->withCount('stores')
                ->orderBy('price')
                ->get();

            $storesByPlan = $plans->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'price' => (float) $plan->price,
                'stores_count' => $plan->stores_count,
                'estimated_mrr' => (float) $plan->price * $stores
                    ->where('subscription_status', 'active')
                    ->where('plan_id', $plan->id)
                    ->count(),
            ]);

            $monthlyChart = collect(range(5, 0))
                ->map(function (int $monthsAgo) use ($now, $ignoredStatus) {
                    $start = $now->copy()->subMonths($monthsAgo)->startOfMonth();
                    $end = $start->copy()->endOfMonth();

                    $ordersQuery = Order::query()
                        ->whereNotIn('status', $ignoredStatus)
                        ->whereBetween('created_at', [$start, $end]);

                    return [
                        'month' => $start->format('Y-m'),
                        'label' => Carbon::parse($start)->translatedFormat('M/y'),
                        'orders_count' => (int) (clone $ordersQuery)->count(),
                        'revenue' => (float) (clone $ordersQuery)->sum('total_amount'),
                    ];
                })
                ->values();

            $topStores = Store::query()
                ->with(['user:id,name,email,phone,role', 'plan'])
                ->withCount(['orders as orders_count' => function ($query) use ($ignoredStatus) {
                    $query->whereNotIn('status', $ignoredStatus);
                }])
                ->withSum(['orders as revenue' => function ($query) use ($ignoredStatus) {
                    $query->whereNotIn('status', $ignoredStatus);
                }], 'total_amount')
                ->orderByDesc('orders_count')
                ->limit(10)
                ->get()
                ->map(fn (Store $store) => [
                    ...$this->formatStore($store),
                    'orders_count' => (int) $store->orders_count,
                    'revenue' => (float) ($store->revenue ?? 0),
                ]);

            return response()->json([
                'cards' => [
                    'total_stores' => $totalStores,
                    'active_stores' => $activeStores,
                    'trial_stores' => $trialStores,
                    'complimentary_stores' => $complimentaryStores,
                    'attention_stores' => $attentionStores,
                    'estimated_mrr' => $estimatedMrr,
                    'month_revenue' => $monthRevenue,
                    'month_orders' => $monthOrders,
                    'last_30_orders' => $last30Orders,
                ],
                'status_distribution' => $statusDistribution,
                'stores_by_plan' => $storesByPlan,
                'monthly_chart' => $monthlyChart,
                'top_stores' => $topStores,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao buscar resumo executivo',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function plans()
    {
        try {
            $plans = Plan::query()
                ->orderBy('price')
                ->get();

            return response()->json($plans);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao buscar planos',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'slug' => ['required', 'string', 'max:255', Rule::unique('plans', 'slug')->ignore($plan->id)],
                'description' => ['nullable', 'string'],
                'price' => ['required', 'numeric', 'min:0'],
                'max_products' => ['nullable', 'integer', 'min:0'],
                'features' => ['nullable', 'array'],
                'features.*' => ['boolean'],
                'is_active' => ['required', 'boolean'],
            ]);

            $updatedPlan = DB::transaction(function () use ($plan, $validated) {
                $plan->update($validated);

                return $plan->fresh();
            });

            return response()->json([
                'message' => 'Plano atualizado com sucesso.',
                'plan' => $updatedPlan,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao atualizar plano',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function stores()
    {
        try {
            $stores = Store::query()
                ->with(['user:id,name,email,phone,role', 'plan'])
                ->latest()
                ->paginate(25);

            return response()->json($stores);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao buscar lojas',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function grantCourtesy(Request $request, Store $store)
    {
        try {
            $validated = $request->validate([
                'plan_id' => ['nullable', 'exists:plans,id'],
                'complimentary_until' => ['nullable', 'date'],
                'complimentary_reason' => ['nullable', 'string', 'max:255'],
            ]);

            $updatedStore = DB::transaction(function () use ($store, $validated) {
                $plan = null;

                if (!empty($validated['plan_id'])) {
                    $plan = Plan::findOrFail($validated['plan_id']);
                }

                $store->update([
                    'plan_id' => $plan?->id ?? $store->plan_id,
                    'plan_type' => $plan?->slug ?? $store->plan_type,
                    'subscription_status' => 'complimentary',
                    'subscription_ends_at' => null,
                    'complimentary_until' => $validated['complimentary_until'] ?? null,
                    'complimentary_reason' => $validated['complimentary_reason'] ?? null,
                ]);

                return $store->fresh(['user', 'plan']);
            });

            return response()->json([
                'message' => 'Cortesia aplicada com sucesso.',
                'store' => $this->formatStore($updatedStore),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao aplicar cortesia',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function updateSubscription(Request $request, Store $store)
    {
        try {
            $validated = $request->validate([
                'plan_id' => ['nullable', 'exists:plans,id'],
                'subscription_status' => [
                    'required',
                    Rule::in(['trial', 'expired_trial', 'active', 'complimentary', 'past_due', 'canceled', 'suspended']),
                ],
                'subscription_ends_at' => ['nullable', 'date'],
            ]);

            $updatedStore = DB::transaction(function () use ($store, $validated) {
                $plan = null;

                if (!empty($validated['plan_id'])) {
                    $plan = Plan::findOrFail($validated['plan_id']);
                }

                $store->update([
                    'plan_id' => $plan?->id,
                    'plan_type' => $plan?->slug,
                    'subscription_status' => $validated['subscription_status'],
                    'subscription_ends_at' => $validated['subscription_ends_at'] ?? null,
                    'complimentary_until' => $validated['subscription_status'] === 'complimentary'
                        ? $store->complimentary_until
                        : null,
                    'complimentary_reason' => $validated['subscription_status'] === 'complimentary'
                        ? $store->complimentary_reason
                        : null,
                ]);

                return $store->fresh(['user', 'plan']);
            });

            return response()->json([
                'message' => 'Assinatura atualizada com sucesso.',
                'store' => $this->formatStore($updatedStore),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao atualizar assinatura',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    private function formatStore(Store $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'plan_id' => $store->plan_id,
            'plan_type' => $store->plan_type,
            'subscription_status' => $store->subscription_status,
            'subscription_ends_at' => $store->subscription_ends_at,
            'complimentary_until' => $store->complimentary_until,
            'complimentary_reason' => $store->complimentary_reason,
            'has_active_subscription' => $store->hasActiveSubscription(),
            'plan' => $store->plan,
            'user' => $store->user ? [
                'id' => $store->user->id,
                'name' => $store->user->name,
                'email' => $store->user->email,
                'phone' => $store->user->phone,
                'role' => $store->user->role,
            ] : null,
        ];
    }

    private function subscriptionStatusLabel(string $status): string
    {
        return [
            'trial' => 'Teste',
            'expired_trial' => 'Teste expirado',
            'active' => 'Ativas',
            'complimentary' => 'Cortesias',
            'past_due' => 'Pendentes',
            'canceled' => 'Canceladas',
            'suspended' => 'Suspensas',
            'unknown' => 'Sem status',
        ][$status] ?? 'Sem status';
    }
}
