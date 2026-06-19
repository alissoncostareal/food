<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Http\Resources\ProductCategoryResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\StoreResource;
use App\Models\Plan;
use App\Models\Product;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Services\ImageService;
use App\Services\StoreSetupProgressService;
use App\Services\WhatsappProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreController extends Controller
{
    use ResolvesMerchantStore;

    public function index()
    {
        try {
            $stores = Store::query()
                ->get()
                ->filter(fn (Store $store) => $store->hasActiveSubscription())
                ->values();

            return StoreResource::collection($stores);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar lojas',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function onboardingStatus(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'needs_store' => $user->needsStoreOnboarding(),
            'has_matriz' => Store::query()
                ->where('user_id', $user->id)
                ->where(function ($query) {
                    $query->where('store_type', Store::TYPE_MATRIZ)
                        ->orWhereNull('store_type');
                })
                ->exists(),
        ]);
    }

    public function createMatriz(Request $request)
    {
        $user = $request->user();

        if (! $user->isStoreOwner()) {
            return response()->json([
                'message' => 'Apenas o dono da conta pode criar a loja matriz.',
            ], 403);
        }

        if ($user->store()->exists()) {
            return response()->json([
                'message' => 'Você já possui uma loja matriz cadastrada.',
            ], 422);
        }

        if (Store::query()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'Você já possui uma loja cadastrada.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:stores,slug', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ]);

        return DB::transaction(function () use ($user, $validated) {
            $trialPlan = Plan::query()
                ->where('slug', 'trial')
                ->where('is_active', true)
                ->first();

            $fallbackStarterPlan = Plan::query()
                ->where('slug', 'starter')
                ->where('is_active', true)
                ->first();

            $initialPlan = $trialPlan ?: $fallbackStarterPlan;

            if (! $initialPlan) {
                return response()->json([
                    'message' => 'Plano Trial não encontrado. Configure o plano trial antes de cadastrar novas lojas.',
                ], 422);
            }

            $slug = filled($validated['slug'] ?? null)
                ? Str::slug($validated['slug'])
                : Str::slug($validated['name']);

            $store = Store::create([
                'user_id' => $user->id,
                'name' => $validated['name'],
                'slug' => $slug,
                'store_type' => Store::TYPE_MATRIZ,
                'parent_store_id' => null,
                'is_open' => true,
                'plan_id' => $initialPlan->id,
                'plan_type' => $initialPlan->slug,
                'subscription_status' => 'trial',
                'subscription_ends_at' => now()->addDays(7),
                'accepted_payment_methods' => Store::PAYMENT_METHODS,
            ]);

            $user->update(['current_store_id' => $store->id]);
            $store->load('plan');

            return response()->json([
                'message' => 'Loja matriz criada com sucesso!',
                'store' => new StoreResource($store),
            ], 201);
        });
    }

    public function listBranches(Request $request)
    {
        $user = $request->user();
        $matriz = $user->store()->with('plan')->first();

        if (! $matriz) {
            return response()->json([
                'message' => 'Crie a loja matriz antes de gerenciar filiais.',
            ], 422);
        }

        if (! $user->ownsStore($matriz)) {
            return response()->json([
                'message' => 'Apenas o dono pode gerenciar filiais.',
            ], 403);
        }

        $branches = Store::query()
            ->where('user_id', $user->id)
            ->where('store_type', Store::TYPE_FILIAL)
            ->where('parent_store_id', $matriz->id)
            ->orderBy('name')
            ->get();

        $totalStores = Store::query()->where('user_id', $user->id)->count();

        return response()->json([
            'matriz' => new StoreResource($matriz),
            'branches' => StoreResource::collection($branches),
            'limits' => [
                'max_stores' => $matriz->maxStoresAllowed(),
                'current_stores' => $totalStores,
                'can_create_branch' => $totalStores < $matriz->maxStoresAllowed(),
                'extra_branch_monthly_price' => PlatformSetting::extraBranchMonthlyPrice(),
            ],
        ]);
    }

    public function createBranch(Request $request)
    {
        $user = $request->user();
        $matriz = $user->store()->with('plan')->first();

        if (! $matriz || ! $user->ownsStore($matriz)) {
            return response()->json([
                'message' => 'Apenas o dono da matriz pode criar filiais.',
            ], 403);
        }

        $totalStores = Store::query()->where('user_id', $user->id)->count();

        if ($totalStores >= $matriz->maxStoresAllowed()) {
            return response()->json([
                'message' => 'Limite de lojas do plano atingido. Faça upgrade para adicionar mais filiais.',
                'limits' => [
                    'max_stores' => $matriz->maxStoresAllowed(),
                    'current_stores' => $totalStores,
                ],
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:stores,slug', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ]);

        $slug = filled($validated['slug'] ?? null)
            ? Str::slug($validated['slug'])
            : Str::slug($validated['name']);

        $branch = Store::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'slug' => $slug,
            'store_type' => Store::TYPE_FILIAL,
            'parent_store_id' => $matriz->id,
            'is_open' => true,
            'plan_id' => $matriz->plan_id,
            'plan_type' => $matriz->plan_type,
            'subscription_status' => $matriz->subscription_status,
            'subscription_ends_at' => $matriz->subscription_ends_at,
            'accepted_payment_methods' => $matriz->acceptedPaymentMethods(),
        ]);

        $branch->load('plan');

        if ($branch->canUseFeature('whatsapp_auto')) {
            app(WhatsappProvisioningService::class)->queueProvisioningForMatriz($matriz->fresh(['plan', 'branches.plan']));
        }

        return response()->json([
            'message' => 'Filial criada com sucesso!',
            'branch' => new StoreResource($branch->load('plan')),
        ], 201);
    }

    public function listAccessible(Request $request)
    {
        $user = $request->user();

        $stores = Store::query()
            ->when(
                $user->isStoreOwner(),
                fn ($query) => $query->where('user_id', $user->id),
                fn ($query) => $query->whereIn('id', $user->storeMemberships()->pluck('store_id'))
            )
            ->with(['plan', 'parentStore:id,name,slug'])
            ->orderByRaw("CASE WHEN store_type = 'matriz' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();

        return response()->json([
            'stores' => StoreResource::collection($stores)->resolve(),
            'current_store_id' => $user->current_store_id ?: $stores->first()?->id,
        ]);
    }

    public function switchStore(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
        ]);

        $store = Store::query()->findOrFail($validated['store_id']);

        if (! $user->canAccessStore($store)) {
            return response()->json([
                'message' => 'Acesso negado a esta loja.',
            ], 403);
        }

        $user->update(['current_store_id' => $store->id]);
        $store->load(['plan', 'user', 'parentStore']);

        return response()->json([
            'message' => 'Loja alternada com sucesso.',
            'store' => new StoreResource($store),
            'current_store_id' => $store->id,
        ]);
    }

    public function me(Request $request)
    {
        try {
            $store = $request->attributes->get('merchant_store')
                ?? Auth::user()->resolveMerchantStore();

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não vinculada ao usuário.',
                ], 404);
            }

            $store->load(['plan', 'user']);

            return new StoreResource($store);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar dados da loja',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function setupProgress(Request $request, StoreSetupProgressService $progress)
    {
        $store = $request->attributes->get('merchant_store')
            ?? Auth::user()?->resolveMerchantStore();

        if (! $store) {
            return response()->json([
                'error' => 'Loja não vinculada ao usuário.',
            ], 404);
        }

        return response()->json($progress->build($store));
    }

    public function store(Request $request)
    {
        try {
            $store = Store::create($request->all());

            return new StoreResource($store);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao criar loja',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function show(string $id)
    {
        try {
            $store = Store::findOrFail($id);

            return new StoreResource($store);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Loja não encontrada',
                'details' => $e->getMessage(),
            ], 404);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $store = Store::findOrFail($id);

            Gate::authorize('update', $store);

            $store->update($request->all());

            return new StoreResource($store);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao atualizar loja',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function updateSettings(Request $request)
    {
        $store = null;

        try {
            $user = Auth::user();
            $store = $request->attributes->get('merchant_store')
                ?? $user->resolveMerchantStore();

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não vinculada ao usuário.',
                ], 404);
            }

            if (! $user->canAccessStore($store)) {
                return response()->json([
                    'message' => 'Acesso negado a esta loja.',
                ], 403);
            }

            $isOwner = $user->ownsStore($store);

            $rules = [
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'instagram_link' => ['nullable', 'string'],
                'whatsapp_number' => ['nullable', 'string'],
                'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'address' => ['nullable', 'string'],
                'is_open' => ['required'],
                'delivery_fee' => ['required', 'numeric'],
                'business_hours' => ['nullable'],
                'accepted_payment_methods' => ['nullable'],
                'online_payments_enabled' => ['nullable'],
            ];

            if ($request->hasFile('logo')) {
                $rules['logo'] = ['file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'];
            }

            if ($request->hasFile('banner')) {
                $rules['banner'] = ['file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'];
            }

            if ($isOwner) {
                $rules['slug'] = ['required', 'string', 'unique:stores,slug,' . $store->id];
            }

            $validated = $request->validate($rules);

            $data = $validated;
            $data['is_open'] = filter_var($request->is_open, FILTER_VALIDATE_BOOLEAN);

            if (! $data['is_open']) {
                $data['open_outside_hours'] = false;
            } elseif (! $store->isWithinScheduledHours()) {
                $data['open_outside_hours'] = true;
            } else {
                $data['open_outside_hours'] = false;
            }

            if ($request->has('online_payments_enabled')) {
                $data['online_payments_enabled'] = filter_var(
                    $request->online_payments_enabled,
                    FILTER_VALIDATE_BOOLEAN
                );
            }

            if ($request->has('business_hours')) {
                $data['business_hours'] = is_string($request->business_hours)
                    ? json_decode($request->business_hours, true)
                    : $request->business_hours;
            }

            if ($request->has('accepted_payment_methods')) {
                $rawMethods = is_string($request->accepted_payment_methods)
                    ? json_decode($request->accepted_payment_methods, true)
                    : $request->accepted_payment_methods;

                $methods = array_values(array_intersect(
                    (array) $rawMethods,
                    Store::PAYMENT_METHODS
                ));

                if ($methods === []) {
                    return response()->json([
                        'message' => 'Selecione pelo menos uma forma de pagamento.',
                    ], 422);
                }

                $data['accepted_payment_methods'] = $methods;
            }

            if (! empty($data['online_payments_enabled'])) {
                $methods = $data['accepted_payment_methods'] ?? $store->acceptedPaymentMethods();

                if (! in_array(Store::PAYMENT_PIX_ONLINE, $methods, true)) {
                    $methods[] = Store::PAYMENT_PIX_ONLINE;
                    $data['accepted_payment_methods'] = array_values(array_unique($methods));
                }
            }

            if ($request->hasFile('logo')) {
                ImageService::deleteStored($store->logo_url);
                $data['logo_url'] = ImageService::upload($request->file('logo'), 'logos');
            }

            if ($request->hasFile('banner')) {
                ImageService::deleteStored($store->banner_url);
                $data['banner_url'] = ImageService::upload($request->file('banner'), 'banners');
            }

            unset($data['logo'], $data['banner']);

            $store->update($data);

            if (! empty($data['business_hours']) && is_array($data['business_hours'])) {
                $store->syncOperatingHoursFromBusinessHours($data['business_hours']);
            }

            return response()->json([
                'message' => 'Configurações atualizadas com sucesso!',
                'store' => new StoreResource($store->fresh()),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Store settings update failed', [
                'store_id' => $store?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Erro ao salvar configurações',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $store = Store::findOrFail($id);

            Gate::authorize('delete', $store);

            $store->delete();

            return response()->json([
                'message' => 'Loja removida com sucesso',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao remover loja',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function showBySlug($slug)
    {
        try {
            $store = Store::where('slug', $slug)
                ->with([
                    'plan',
                    'paymentPixProvider',
                    'productCategories' => function ($query) {
                        $query->orderBy('position', 'asc')
                            ->orderBy('id', 'asc')
                            ->with([
                                'products' => function ($pQuery) {
                                    $pQuery->where('is_active', true)
                                        ->orderBy('products.name')
                                        ->with(['optionGroups.optionItems']);
                                },
                            ]);
                    },
                ])
                ->firstOrFail();

            $isExpired = ! $store->hasActiveSubscription();
            $isInGrace = ! $isExpired && $store->subscription_ends_at && now()->gt($store->subscription_ends_at);
            $isOpenReal = $store->is_open_now && ! $isExpired;
            $openingStatus = $store->opening_status;
            $statusMessage = $isExpired
                ? 'Loja indisponível'
                : ($isInGrace ? 'Assinatura pendente — regularize em até 7 dias' : ($isOpenReal ? 'Aberto agora' : ($openingStatus['message'] ?? 'Fechado')));

            $cartHighlights = collect();

            if (Schema::hasColumn('products', 'show_in_cart')) {
                $cartHighlights = Product::query()
                    ->where('store_id', $store->id)
                    ->where('is_active', true)
                    ->where('show_in_cart', true)
                    ->orderByRaw('cart_highlight_order IS NULL')
                    ->orderBy('cart_highlight_order')
                    ->orderBy('name')
                    ->limit(12)
                    ->with(['optionGroups.optionItems', 'category'])
                    ->get();
            }

            $deliverySummary = $this->buildDeliverySummary($store);

            return response()->json([
                'store' => new StoreResource($store),
                'is_open' => $isOpenReal,
                'status_message' => $statusMessage,
                'opening_status' => [
                    ...$openingStatus,
                    'is_open' => $isOpenReal,
                    'message' => $statusMessage,
                    'next_opening' => $isExpired ? null : ($openingStatus['next_opening'] ?? null),
                    'hours_hint' => $isExpired ? null : ($openingStatus['hours_hint'] ?? null),
                    'accepts_orders_until' => $isExpired ? null : ($openingStatus['accepts_orders_until'] ?? null),
                ],
                'next_opening' => $isExpired ? null : ($openingStatus['next_opening'] ?? null),
                'delivery_summary' => $deliverySummary,
                'categories' => ProductCategoryResource::collection($store->productCategories),
                'cart_highlights' => ProductResource::collection($cartHighlights),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Loja não encontrada',
                'details' => $e->getMessage(),
            ], 404);
        }
    }

    public function updateAppearance(Request $request)
    {
        try {
            $store = $this->merchantStore();

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não vinculada ao usuário.',
                ], 404);
            }

            $rules = [
                'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            ];

            if ($request->hasFile('logo')) {
                $rules['logo'] = ['file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'];
            }

            if ($request->hasFile('banner')) {
                $rules['banner'] = ['file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'];
            }

            $validated = $request->validate($rules);

            if ($request->hasFile('logo')) {
                ImageService::deleteStored($store->logo_url);
                $validated['logo_url'] = ImageService::upload($request->file('logo'), 'logos');
            }

            if ($request->hasFile('banner')) {
                ImageService::deleteStored($store->banner_url);
                $validated['banner_url'] = ImageService::upload($request->file('banner'), 'banners');
            }

            unset($validated['logo'], $validated['banner']);

            $store->update($validated);

            return response()->json([
                'message' => 'Aparência atualizada com sucesso!',
                'store' => new StoreResource($store->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao atualizar aparência',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function toggleOpen()
    {
        try {
            $store = $this->merchantStore();

            if (!$store instanceof Store) {
                return response()->json([
                    'error' => 'Loja não configurada.',
                ], 404);
            }

            $currentlyOpen = $store->is_open_now;
            $withinHours = $store->isWithinScheduledHours();

            if ($currentlyOpen) {
                $store->update([
                    'is_open' => false,
                    'open_outside_hours' => false,
                ]);
            } else {
                $store->update([
                    'is_open' => true,
                    'open_outside_hours' => ! $withinHours,
                ]);
            }

            $store->refresh();

            $withinHours = $store->isWithinScheduledHours();

            $message = $store->is_open_now
                ? ($withinHours
                    ? 'Loja aberta!'
                    : 'Loja aberta! Recebendo pedidos fora do horário cadastrado.')
                : 'Loja fechada!';

            return response()->json([
                'message' => $message,
                'is_open' => (bool) $store->is_open_now,
                'manual_is_open' => (bool) $store->is_open,
                'open_outside_hours' => (bool) $store->open_outside_hours,
                'within_scheduled_hours' => $withinHours,
                'opening_status' => $store->opening_status,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao alterar status',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function updateOperatingHours(Request $request)
    {
        $validated = $request->validate([
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'hours.*.opening_time' => ['required', 'date_format:H:i'],
            'hours.*.closing_time' => ['required', 'date_format:H:i'],
            'hours.*.is_closed' => ['required', 'boolean'],
        ]);

        try {
            $store = $this->merchantStore();

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não configurada.',
                ], 404);
            }

            DB::transaction(function () use ($store, $validated) {
                foreach ($validated['hours'] as $hour) {
                    $store->operatingHours()->updateOrCreate(
                        [
                            'day_of_week' => $hour['day_of_week'],
                        ],
                        [
                            'opening_time' => $hour['opening_time'],
                            'closing_time' => $hour['closing_time'],
                            'is_closed' => $hour['is_closed'],
                        ]
                    );
                }
            });

            return response()->json([
                'message' => 'Horários de funcionamento atualizados!',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao salvar horários',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    private function buildDeliverySummary(Store $store): array
    {
        $fee = (float) ($store->delivery_fee ?? 0);

        if (! $store->canUseFeature('delivery_areas')) {
            return [
                'mode' => 'fixed',
                'fee' => $fee,
            ];
        }

        $fees = $store->deliveryAreas()
            ->where('is_active', true)
            ->pluck('fee')
            ->map(fn ($value) => (float) $value);

        if ($fees->isEmpty()) {
            return [
                'mode' => 'fixed',
                'fee' => $fee,
            ];
        }

        return [
            'mode' => 'areas',
            'min_fee' => (float) $fees->min(),
            'max_fee' => (float) $fees->max(),
            'count' => $fees->count(),
            'fallback_fee' => $fee,
        ];
    }
}
