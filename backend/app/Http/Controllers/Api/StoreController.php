<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCategoryResource;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class StoreController extends Controller
{
    public function index()
    {
        try {
            $stores = Store::where('subscription_ends_at', '>=', now())
                ->orWhere('subscription_status', 'trial')
                ->get();

            return StoreResource::collection($stores);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar lojas',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function me()
    {
        try {
            $store = Auth::user()->store()->with('plan')->first();

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não vinculada ao usuário.',
                ], 404);
            }

            return new StoreResource($store);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar dados da loja',
                'details' => $e->getMessage(),
            ], 500);
        }
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
        try {
            $store = Auth::user()->store;

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não vinculada ao usuário.',
                ], 404);
            }

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'instagram_link' => ['nullable', 'string'],
                'whatsapp_number' => ['nullable', 'string'],
                'slug' => ['required', 'string', 'unique:stores,slug,' . $store->id],
                'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'address' => ['nullable', 'string'],
                'is_open' => ['required'],
                'delivery_fee' => ['required', 'numeric'],
                'business_hours' => ['nullable'],
                'logo' => ['nullable', 'image', 'max:2048'],
                'banner' => ['nullable', 'image', 'max:4096'],
            ]);

            $data = $validated;
            $data['is_open'] = filter_var($request->is_open, FILTER_VALIDATE_BOOLEAN);

            if ($request->has('business_hours')) {
                $data['business_hours'] = is_string($request->business_hours)
                    ? json_decode($request->business_hours, true)
                    : $request->business_hours;
            }

            if ($request->hasFile('logo')) {
                if ($store->logo_url) {
                    $oldPath = str_replace(asset('storage/'), '', $store->logo_url);
                    Storage::disk('public')->delete($oldPath);
                }

                $path = $request->file('logo')->store('logos', 'public');
                $data['logo_url'] = asset('storage/' . $path);
            }

            if ($request->hasFile('banner')) {
                if ($store->banner_url) {
                    $oldPath = str_replace(asset('storage/'), '', $store->banner_url);
                    Storage::disk('public')->delete($oldPath);
                }

                $path = $request->file('banner')->store('banners', 'public');
                $data['banner_url'] = asset('storage/' . $path);
            }

            unset($data['logo'], $data['banner']);

            $store->update($data);

            return response()->json([
                'message' => 'Configurações atualizadas com sucesso!',
                'store' => new StoreResource($store->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao salvar configurações',
                'details' => $e->getMessage(),
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
                    'productCategories' => function ($query) {
                        $query->orderBy('position', 'asc')
                            ->with([
                                'products' => function ($pQuery) {
                                    $pQuery->with(['optionGroups.optionItems']);
                                },
                            ]);
                    },
                ])
                ->firstOrFail();

            $isExpired = $store->subscription_ends_at && now()->gt($store->subscription_ends_at);
            $isOpenReal = $store->is_open_now && !$isExpired;
            $openingStatus = $store->opening_status;
            $statusMessage = $isExpired
                ? 'Assinatura pendente'
                : ($isOpenReal ? 'Aberto agora' : ($openingStatus['message'] ?? 'Fechado'));

            return response()->json([
                'store' => new StoreResource($store),
                'is_open' => $isOpenReal,
                'status_message' => $statusMessage,
                'opening_status' => [
                    ...$openingStatus,
                    'is_open' => $isOpenReal,
                    'message' => $statusMessage,
                    'next_opening' => $isExpired ? null : ($openingStatus['next_opening'] ?? null),
                ],
                'next_opening' => $isExpired ? null : ($openingStatus['next_opening'] ?? null),
                'categories' => ProductCategoryResource::collection($store->productCategories),
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
            $store = Auth::user()->store;

            if (!$store) {
                return response()->json([
                    'error' => 'Loja não vinculada ao usuário.',
                ], 404);
            }

            $validated = $request->validate([
                'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'logo' => ['nullable', 'image', 'max:2048'],
                'banner' => ['nullable', 'image', 'max:4096'],
            ]);

            if ($request->hasFile('logo')) {
                $validated['logo_url'] = ImageService::upload($request->file('logo'), 'logos');
            }

            if ($request->hasFile('banner')) {
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
            $store = Auth::user()->store;

            if (!$store instanceof Store) {
                return response()->json([
                    'error' => 'Loja não configurada.',
                ], 404);
            }

            $store->update([
                'is_open' => !$store->is_open,
            ]);

            return response()->json([
                'message' => $store->is_open ? 'Loja aberta!' : 'Loja fechada!',
                'is_open' => (bool) $store->is_open_now,
                'manual_is_open' => (bool) $store->is_open,
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
            $store = Auth::user()->store;

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
}
