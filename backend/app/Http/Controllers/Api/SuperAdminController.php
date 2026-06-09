<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class SuperAdminController extends Controller
{
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
                    Rule::in(['trial', 'active', 'complimentary', 'past_due', 'canceled', 'suspended']),
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
}
