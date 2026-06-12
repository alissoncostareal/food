<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesMerchantStore;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MerchantCouponController extends Controller
{
    use ResolvesMerchantStore;

    public function index()
    {
        try {
            $store = $this->merchantStore();

            $coupons = Coupon::where('store_id', $store->id)
                ->latest()
                ->get();

            return response()->json([
                'data' => $coupons,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar cupons',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function store(Request $request)
    {
        try {
            $store = $this->merchantStore();

            $validated = $request->validate([
                'code' => [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('coupons')->where(fn ($query) => $query->where('store_id', $store->id)),
                ],
                'description' => ['nullable', 'string', 'max:255'],
                'type' => ['required', Rule::in(['percentage', 'fixed'])],
                'value' => ['required', 'numeric', 'min:0.01'],
                'min_order_amount' => ['nullable', 'numeric', 'min:0'],
                'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
                'usage_limit' => ['nullable', 'integer', 'min:1'],
                'expires_at' => ['nullable', 'date'],
            ]);

            if ($validated['type'] === 'percentage' && (float) $validated['value'] > 100) {
                return response()->json([
                    'message' => 'O desconto percentual não pode ser maior que 100%.',
                    'errors' => [
                        'value' => ['O desconto percentual não pode ser maior que 100%.'],
                    ],
                ], 422);
            }

            $coupon = DB::transaction(function () use ($store, $validated) {
                return Coupon::create([
                    'store_id' => $store->id,
                    'code' => strtoupper(trim($validated['code'])),
                    'description' => $validated['description'] ?? null,
                    'type' => $validated['type'],
                    'value' => $validated['value'],
                    'min_order_amount' => $validated['min_order_amount'] ?? null,
                    'max_discount_amount' => $validated['max_discount_amount'] ?? null,
                    'usage_limit' => $validated['usage_limit'] ?? null,
                    'expires_at' => $validated['expires_at'] ?? null,
                    'is_active' => true,
                ]);
            });

            return response()->json([
                'message' => 'Cupom criado com sucesso.',
                'data' => $coupon,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao criar cupom',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function show(Coupon $coupon)
    {
        try {
            $this->authorizeCoupon($coupon);

            return response()->json([
                'data' => $coupon,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Cupom não encontrado',
                'details' => $e->getMessage(),
            ], 404);
        }
    }

    public function update(Request $request, Coupon $coupon)
    {
        try {
            $this->authorizeCoupon($coupon);

            $validated = $request->validate([
                'code' => [
                    'sometimes',
                    'string',
                    'max:50',
                    Rule::unique('coupons')
                        ->where(fn ($query) => $query->where('store_id', $coupon->store_id))
                        ->ignore($coupon->id),
                ],
                'description' => ['nullable', 'string', 'max:255'],
                'type' => ['sometimes', Rule::in(['percentage', 'fixed'])],
                'value' => ['sometimes', 'numeric', 'min:0.01'],
                'min_order_amount' => ['nullable', 'numeric', 'min:0'],
                'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
                'usage_limit' => ['nullable', 'integer', 'min:1'],
                'expires_at' => ['nullable', 'date'],
            ]);

            $type = $validated['type'] ?? $coupon->type;
            $value = $validated['value'] ?? $coupon->value;

            if ($type === 'percentage' && (float) $value > 100) {
                return response()->json([
                    'message' => 'O desconto percentual não pode ser maior que 100%.',
                    'errors' => [
                        'value' => ['O desconto percentual não pode ser maior que 100%.'],
                    ],
                ], 422);
            }

            if (isset($validated['code'])) {
                $validated['code'] = strtoupper(trim($validated['code']));
            }

            DB::transaction(function () use ($coupon, $validated) {
                $coupon->update($validated);
            });

            return response()->json([
                'message' => 'Cupom atualizado com sucesso.',
                'data' => $coupon->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao atualizar cupom',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function destroy(Coupon $coupon)
    {
        try {
            $this->authorizeCoupon($coupon);

            $result = DB::transaction(function () use ($coupon) {
                $hasUsage = DB::table('coupon_usages')
                    ->where('coupon_id', $coupon->id)
                    ->exists();

                $hasOrdersById = DB::table('orders')
                    ->where('coupon_id', $coupon->id)
                    ->exists();

                $hasOrdersBySnapshot = DB::table('orders')
                    ->where('store_id', $coupon->store_id)
                    ->where('coupon_code', $coupon->code)
                    ->exists();

                $hasUsedCount = (int) ($coupon->used_count ?? 0) > 0;

                if ($hasUsage || $hasOrdersById || $hasOrdersBySnapshot || $hasUsedCount) {
                    $coupon->update([
                        'is_active' => false,
                    ]);

                    return [
                        'message' => 'Cupom já possui uso vinculado e foi pausado para preservar o histórico.',
                        'deleted' => false,
                        'is_active' => false,
                        'data' => $coupon->fresh(),
                    ];
                }

                $coupon->delete();

                return [
                    'message' => 'Cupom removido com sucesso.',
                    'deleted' => true,
                    'is_active' => null,
                    'data' => null,
                ];
            });

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao remover cupom',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function toggle(Coupon $coupon)
    {
        try {
            $this->authorizeCoupon($coupon);

            DB::transaction(function () use ($coupon) {
                $coupon->update([
                    'is_active' => !$coupon->is_active,
                ]);
            });

            $coupon = $coupon->fresh();
            $status = $coupon->is_active ? 'ativo' : 'pausado';

            return response()->json([
                'message' => "O cupom agora está {$status}!",
                'data' => $coupon,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao atualizar status do cupom',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    private function authorizeCoupon(Coupon $coupon): void
    {
        $store = $this->merchantStore();

        if ((int) $coupon->store_id !== (int) $store->id) {
            throw new \Exception('Este cupom não pertence à sua loja.');
        }
    }
}
