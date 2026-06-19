<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationErrorLog;
use App\Models\LandingLead;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Services\IfoodService;
use App\Services\LandingPageService;
use App\Services\WhatsappProvisioningService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class SuperAdminController extends Controller
{
    private const CORE_PLAN_SLUGS = ['trial', 'starter', 'pro', 'premium'];

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
                'max_stores' => ['required', 'integer', 'min:1'],
                'max_team_members' => ['nullable', 'integer', 'min:0'],
                'features' => ['nullable', 'array'],
                'features.*' => ['boolean'],
                'is_active' => ['required', 'boolean'],
                'is_visible' => ['sometimes', 'boolean'],
            ]);

            if (in_array($plan->slug, self::CORE_PLAN_SLUGS, true) && $validated['slug'] !== $plan->slug) {
                return response()->json([
                    'message' => 'O slug de planos nativos não pode ser alterado. Edite o nome exibido para o lojista.',
                ], 422);
            }

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

    public function togglePlanVisibility(Plan $plan)
    {
        try {
            $plan->update([
                'is_visible' => ! $plan->is_visible,
            ]);

            return response()->json([
                'message' => $plan->is_visible
                    ? 'Plano visível na vitrine do lojista.'
                    : 'Plano oculto da vitrine do lojista.',
                'plan' => $plan->fresh(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao alterar visibilidade do plano',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function stores(Request $request)
    {
        try {
            $perPage = min(max((int) $request->query('per_page', 50), 10), 200);

            $stores = Store::query()
                ->with(['user:id,name,email,phone,role', 'plan', 'parentStore:id,name,slug'])
                ->withCount('branches')
                ->latest()
                ->paginate($perPage);

            return response()->json(
                $stores->through(fn (Store $store) => $this->formatStore($store))
            );
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
                'password' => ['required', 'string'],
                'plan_id' => ['required', 'exists:plans,id'],
                'complimentary_until' => ['required', 'date'],
                'complimentary_reason' => ['nullable', 'string', 'max:255'],
            ]);

            if (! Hash::check($validated['password'], $request->user()->password)) {
                throw ValidationException::withMessages([
                    'password' => ['Senha incorreta. Confirme sua senha de super admin.'],
                ]);
            }

            $updatedStore = DB::transaction(function () use ($store, $validated) {
                $plan = Plan::findOrFail($validated['plan_id']);
                $complimentaryUntil = Carbon::parse($validated['complimentary_until'])->endOfDay();

                if ($complimentaryUntil->lte(now())) {
                    throw ValidationException::withMessages([
                        'complimentary_until' => ['A data final da cortesia precisa ser futura.'],
                    ]);
                }

                $store->update([
                    'plan_id' => $plan->id,
                    'plan_type' => $plan->slug,
                    'subscription_status' => 'complimentary',
                    'subscription_ends_at' => $complimentaryUntil,
                    'subscription_grace_ends_at' => null,
                    'complimentary_until' => $complimentaryUntil,
                    'complimentary_reason' => $validated['complimentary_reason'] ?? null,
                    ...(blank($store->pre_courtesy_subscription_status)
                        && blank($store->pagarme_subscription_id)
                        && in_array($store->subscription_status, ['trial', 'expired_trial'], true)
                        ? [
                            'pre_courtesy_plan_id' => $store->plan_id,
                            'pre_courtesy_subscription_status' => 'trial',
                            'pre_courtesy_subscription_ends_at' => $store->subscription_ends_at,
                        ]
                        : []),
                ]);

                $store = $store->fresh(['user', 'plan']);
                $store->syncBranchesSubscriptionFromMatriz();

                return $store;
            });

            app(WhatsappProvisioningService::class)->syncAfterPlanChange($updatedStore);

            return response()->json([
                'message' => 'Cortesia aplicada. Lojas em trial voltam ao Trial ao encerrar; demais precisam assinar o plano.',
                'store' => $this->formatStore($updatedStore),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao aplicar cortesia',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function revokeCourtesy(Request $request, Store $store)
    {
        try {
            $validated = $request->validate([
                'password' => ['required', 'string'],
            ]);

            if (! Hash::check($validated['password'], $request->user()->password)) {
                throw ValidationException::withMessages([
                    'password' => ['Senha incorreta. Confirme sua senha de super admin.'],
                ]);
            }

            $wasComplimentary = $store->subscription_status === 'complimentary';

            if (! $wasComplimentary) {
                $canForceEnd = $store->subscription_status === 'past_due'
                    && blank($store->pagarme_subscription_id);

                if (! $canForceEnd) {
                    return response()->json([
                        'message' => 'Esta loja não possui cortesia ativa.',
                    ], 422);
                }
            }

            $updatedStore = DB::transaction(function () use ($store) {
                $store->finalizeCourtesy();

                return $store->fresh(['user', 'plan']);
            });

            app(WhatsappProvisioningService::class)->syncAfterPlanChange($updatedStore);

            $restoredTrial = $updatedStore->subscription_status === 'trial';

            return response()->json([
                'message' => $wasComplimentary
                    ? ($restoredTrial
                        ? 'Cortesia removida. A loja voltou ao plano Trial.'
                        : 'Cortesia removida. A loja precisará assinar um plano para continuar.')
                    : ($restoredTrial
                        ? 'A loja voltou ao plano Trial.'
                        : 'Acesso premium bloqueado. A loja precisa assinar um plano para continuar.'),
                'store' => $this->formatStore($updatedStore),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao remover cortesia',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function updateSubscription(Request $request, Store $store)
    {
        try {
            $validated = $request->validate([
                'password' => ['required', 'string'],
                'plan_id' => ['nullable', 'exists:plans,id'],
                'subscription_status' => [
                    'required',
                    Rule::in(['trial', 'expired_trial', 'active', 'complimentary', 'past_due', 'canceled', 'suspended']),
                ],
                'subscription_ends_at' => ['nullable', 'date'],
            ]);

            if (! Hash::check($validated['password'], $request->user()->password)) {
                throw ValidationException::withMessages([
                    'password' => ['Senha incorreta. Confirme sua senha de super admin.'],
                ]);
            }

            $updatedStore = DB::transaction(function () use ($store, $validated) {
                $plan = null;

                if (!empty($validated['plan_id'])) {
                    $plan = Plan::findOrFail($validated['plan_id']);
                }

                $subscriptionEndsAt = $validated['subscription_ends_at'] ?? $store->subscription_ends_at;

                if ($validated['subscription_status'] === 'active') {
                    if (! $subscriptionEndsAt || Carbon::parse($subscriptionEndsAt)->isPast()) {
                        $subscriptionEndsAt = now()->addMonth();
                    }
                }

                $store->update([
                    'plan_id' => $plan?->id ?? $store->plan_id,
                    'plan_type' => $plan?->slug ?? $store->plan_type,
                    'subscription_status' => $validated['subscription_status'],
                    'subscription_ends_at' => $subscriptionEndsAt,
                    'complimentary_until' => $validated['subscription_status'] === 'complimentary'
                        ? $store->complimentary_until
                        : null,
                    'complimentary_reason' => $validated['subscription_status'] === 'complimentary'
                        ? $store->complimentary_reason
                        : null,
                ]);

                $store = $store->fresh(['user', 'plan']);
                $store->syncBranchesSubscriptionFromMatriz();

                return $store;
            });

            app(WhatsappProvisioningService::class)->syncAfterPlanChange($updatedStore);

            return response()->json([
                'message' => 'Assinatura atualizada com sucesso.',
                'store' => $this->formatStore($updatedStore),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao atualizar assinatura',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function detachBranch(Request $request, Store $store)
    {
        try {
            $validated = $request->validate([
                'password' => ['required', 'string'],
            ]);

            if (! Hash::check($validated['password'], $request->user()->password)) {
                throw ValidationException::withMessages([
                    'password' => ['Senha incorreta. Confirme sua senha de super admin.'],
                ]);
            }

            if (! $store->isFilial()) {
                return response()->json([
                    'message' => 'Somente filiais podem ser tornadas independentes.',
                ], 422);
            }

            $trialPlan = Plan::query()
                ->where('slug', 'trial')
                ->where('is_active', true)
                ->first();

            $updatedStore = DB::transaction(function () use ($store, $trialPlan) {
                $store->update([
                    'store_type' => Store::TYPE_MATRIZ,
                    'parent_store_id' => null,
                    'plan_id' => $trialPlan?->id ?? $store->plan_id,
                    'plan_type' => $trialPlan?->slug ?? $store->plan_type,
                    'subscription_status' => 'trial',
                    'subscription_ends_at' => now()->addDays(7),
                    'subscription_grace_ends_at' => null,
                    'complimentary_until' => null,
                    'complimentary_reason' => null,
                    'pagarme_customer_id' => null,
                    'pagarme_subscription_id' => null,
                    'pagarme_subscription_status' => null,
                    'pagarme_last_charge_id' => null,
                ]);

                return $store->fresh(['user', 'plan', 'parentStore']);
            });

            app(WhatsappProvisioningService::class)->syncAfterPlanChange($updatedStore);

            return response()->json([
                'message' => 'Filial desvinculada. Agora é matriz independente com 7 dias de teste para assinar.',
                'store' => $this->formatStore($updatedStore),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao desvincular filial',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function settings()
    {
        try {
            $definitions = PlatformSetting::editableSettings();
            $values = PlatformSetting::publicValues();

            return response()->json([
                'settings' => collect($definitions)->map(function (array $meta, string $key) use ($values) {
                    return [
                        'key' => $key,
                        'label' => $meta['label'],
                        'type' => $meta['type'],
                        'value' => $values[$key] ?? $meta['default'],
                        'min' => $meta['min'] ?? null,
                        'max' => $meta['max'] ?? null,
                        'hint' => $meta['hint'] ?? null,
                    ];
                })->values(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao buscar configurações',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function updateSettings(Request $request)
    {
        try {
            $definitions = PlatformSetting::editableSettings();

            $rules = [];
            foreach ($definitions as $key => $meta) {
                $rule = ['required'];

                if ($meta['type'] === 'integer') {
                    $rule[] = 'integer';
                    $rule[] = 'min:'.($meta['min'] ?? 0);
                    if (isset($meta['max'])) {
                        $rule[] = 'max:'.$meta['max'];
                    }
                } else {
                    $rule[] = 'numeric';
                    $rule[] = 'min:'.($meta['min'] ?? 0);
                }

                $rules[$key] = $rule;
            }

            $validated = $request->validate($rules);

            DB::transaction(function () use ($validated) {
                foreach ($validated as $key => $value) {
                    PlatformSetting::set($key, $value);
                }
            });

            return response()->json([
                'message' => 'Configurações atualizadas com sucesso.',
                'settings' => PlatformSetting::publicValues(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao atualizar configurações',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function landingPage()
    {
        try {
            return response()->json([
                'content' => LandingPageService::content(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao buscar landing page',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function updateLandingPage(Request $request)
    {
        try {
            $validated = $request->validate([
                'published' => ['required', 'boolean'],
                'hero.eyebrow' => ['required', 'string', 'max:80'],
                'hero.title' => ['required', 'string', 'max:120'],
                'hero.highlight' => ['required', 'string', 'max:80'],
                'hero.subtitle' => ['required', 'string', 'max:500'],
                'hero.cta_primary_text' => ['required', 'string', 'max:60'],
                'hero.cta_primary_url' => ['required', 'string', 'max:190'],
                'hero.cta_secondary_text' => ['required', 'string', 'max:60'],
                'hero.cta_secondary_url' => ['required', 'string', 'max:190'],
                'features_section.title' => ['required', 'string', 'max:120'],
                'features_section.subtitle' => ['required', 'string', 'max:240'],
                'features' => ['required', 'array', 'min:1', 'max:12'],
                'features.*.icon' => ['nullable', 'string', 'max:40'],
                'features.*.title' => ['required', 'string', 'max:80'],
                'features.*.description' => ['required', 'string', 'max:240'],
                'plans_section.title' => ['required', 'string', 'max:120'],
                'plans_section.subtitle' => ['required', 'string', 'max:240'],
                'plans_section.show_plans' => ['required', 'boolean'],
                'cta_section.title' => ['required', 'string', 'max:120'],
                'cta_section.subtitle' => ['required', 'string', 'max:240'],
                'lead_form.enabled' => ['required', 'boolean'],
                'lead_form.title' => ['required', 'string', 'max:120'],
                'lead_form.subtitle' => ['required', 'string', 'max:240'],
                'lead_form.button_text' => ['required', 'string', 'max:60'],
                'lead_form.success_message' => ['required', 'string', 'max:240'],
                'footer.text' => ['required', 'string', 'max:240'],
            ]);

            $content = LandingPageService::save($validated);

            return response()->json([
                'message' => 'Landing page atualizada com sucesso.',
                'content' => $content,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao atualizar landing page',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function landingLeads()
    {
        try {
            $leads = LandingLead::query()
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (LandingLead $lead) => [
                    'id' => $lead->id,
                    'name' => $lead->name,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'store_name' => $lead->store_name,
                    'message' => $lead->message,
                    'created_at' => $lead->created_at?->toIso8601String(),
                ]);

            return response()->json([
                'leads' => $leads,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao buscar leads da landing',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function integrationErrors(Request $request)
    {
        try {
            $perPage = min(max((int) $request->query('per_page', 30), 10), 100);
            $channel = $request->query('channel');

            $query = IntegrationErrorLog::query()
                ->with('store:id,name,slug')
                ->latest();

            if (filled($channel)) {
                $query->where('channel', $channel);
            }

            $logs = $query->paginate($perPage);

            return response()->json(
                $logs->through(fn (IntegrationErrorLog $log) => [
                    'id' => $log->id,
                    'error_ref' => $log->error_ref,
                    'channel' => $log->channel,
                    'action' => $log->action,
                    'public_message' => $log->public_message,
                    'details' => $log->details,
                    'store' => $log->store ? [
                        'id' => $log->store->id,
                        'name' => $log->store->name,
                        'slug' => $log->store->slug,
                    ] : null,
                    'created_at' => $log->created_at?->toIso8601String(),
                ])
            );
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao buscar logs de integração',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 400);
        }
    }

    public function testIfoodCredentials(IfoodService $ifood)
    {
        try {
            return response()->json([
                'message' => 'Credenciais iFood validadas com sucesso.',
                'ifood' => $ifood->testCentralizedCredentials(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Erro ao testar credenciais iFood.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 400);
        }
    }

    private function formatStore(Store $store): array
    {
        $store->ensureSubscriptionStateIsCurrent();

        return [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'store_type' => $store->isFilial() ? Store::TYPE_FILIAL : Store::TYPE_MATRIZ,
            'parent_store_id' => $store->parent_store_id,
            'parent_store' => $store->parentStore ? [
                'id' => $store->parentStore->id,
                'name' => $store->parentStore->name,
                'slug' => $store->parentStore->slug,
            ] : null,
            'branches_count' => (int) ($store->branches_count ?? ($store->isMatriz() ? $store->branches()->count() : 0)),
            'plan_id' => $store->plan_id,
            'plan_type' => $store->plan_type,
            'subscription_status' => $store->subscription_status,
            'subscription_ends_at' => $store->subscription_ends_at,
            'complimentary_until' => $store->complimentary_until,
            'complimentary_reason' => $store->complimentary_reason,
            'has_active_subscription' => $store->hasActiveSubscription(),
            'panel_access' => $store->panelAccessState(),
            'is_within_payment_grace' => $store->isWithinPaymentGrace(),
            'payment_grace_ends_at' => $store->paymentGraceEndsAt(),
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
