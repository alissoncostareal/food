<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MerchantCouponController extends Controller
{
    public function index()
    {
        try {
            $store = Auth::user()?->store;

            if (!$store) {
                return response()->json([
                    'message' => 'Loja não encontrada para este usuário.'
                ], 404);
            }

            $coupons = Coupon::where('store_id', $store->id)
            ->latest()
            ->get();

            return response()->json([
                'data' => $coupons
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao carregar cupons', 'details' => $e->getMessage()], 400);
        }
    }

    public function store(Request $request)
    {
        try {
            $store = Auth::user()?->store;

            if (!$store) {
                return response()->json([
                    'message' => 'Loja não encontrada para este usuário.'
                ], 404);
            }

            $validated = $request->validate([
                'code' => [
                    'required',
                    'string',
                    'max:50',
                    Rule::unique('coupons')->where(function ($query) use ($store) {
                        return $query->where('store_id', $store->id);
                    }),
                ],
                'description' => ['nullable', 'string', 'max:255'],
                'type' => ['required', Rule::in(['percentage', 'fixed'])],
                'value' => ['required', 'numeric', 'min:0.01'],
                'min_order_amount' => ['nullable', 'numeric', 'min:0'],
                'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
                'usage_limit' => ['nullable', 'integer', 'min:1'],
                'expires_at' => ['nullable', 'date'],
            ]);

            if ($validated['type'] === 'percentage' && $validated['value'] > 100) {
                return response()->json([
                    'message' => 'O desconto percentual não pode ser maior que 100%.',
                    'errors' => [
                        'value' => ['O desconto percentual não pode ser maior que 100%.']
                    ]
                ], 422);
            }
            DB::beginTransaction();
            $coupon = Coupon::create([
                'store_id' => $store->id,
                'code' => strtoupper(trim($validated['code'])),
                'description' => $validated['description'] ?? null,
                'type' => $validated['type'],
                'value' => $validated['value'],
                'min_order_amount' => $validated['min_order_amount'] ?? null,
                'max_discount_amount' => $validated['max_discount_amount'] ?? null,
                'usage_limit' => $validated['usage_limit'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
            ]);
            DB::commit();
            return response()->json([
                'message' => 'Cupom criado com sucesso.',
                'data' => $coupon
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Erro ao criar cupom', 'details' => $e->getMessage()], 400);
        }
    }

    public function show(Coupon $coupon)
    {
        try {
            $this->authorizeCoupon($coupon);
            return response()->json([
                'data' => $coupon
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Cupom não encontrado'], 404);
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
                    Rule::unique('coupons')->where(function ($query) use ($coupon) {
                        return $query->where('store_id', $coupon->store_id);
                    })->ignore($coupon->id),
                ],
                'description' => ['nullable', 'string', 'max:255'],
                'type' => ['sometimes', Rule::in(['percentage', 'fixed'])],
                'value' => ['sometimes', 'numeric', 'min:0.01'],
                'min_order_amount' => ['nullable', 'numeric', 'min:0'],
                'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
                'usage_limit' => ['nullable', 'integer', 'min:1'],
                'expires_at' => ['nullable', 'date'],
            ]);
            DB::beginTransaction();
            if (isset($validated['type']) && $validated['type'] === 'percentage' && isset($validated['value']) && $validated['value'] > 100) {
                return response()->json([
                    'message' => 'O desconto percentual não pode ser maior que 100%.',
                    'errors' => [
                        'value' => ['O desconto percentual não pode ser maior que 100%.']
                    ]
                ], 422);
            }

            $coupon->update($validated);
            DB::commit();
            return response()->json([
                'message' => 'Cupom atualizado com sucesso.',
                'data' => $coupon
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Erro ao atualizar cupom', 'details' => $e->getMessage()], 400);
        }
    }

    public function destroy(Coupon $coupon)
    {
        try {
            DB::beginTransaction();
            $this->authorizeCoupon($coupon);
            $coupon->delete();
            DB::commit();
            return response()->json(['message' => 'Cupom removido com sucesso']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Erro ao remover cupom', 'details' => $e->getMessage()], 400);
        }
    }

    public function toggle(Coupon $coupon)
    {
        try {
            DB::beginTransaction();

            $this->authorizeCoupon($coupon);

            $coupon->update([
                'is_active' => !$coupon->is_active
            ]);

            $coupon = $coupon->fresh();

            $status = $coupon->is_active ? 'ativo' : 'pausado';

            DB::commit();

            return response()->json([
                'message' => "O cupom agora está {$status}!",
                'data' => $coupon
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Erro ao atualizar status do cupom',
                'details' => $e->getMessage()
            ], 400);
        }
    }

    private function authorizeCoupon(Coupon $coupon): void
    {
        try {
            $store = Auth::user()?->store;

            if (!$store) {
                throw new \Exception('Loja não encontrada para este usuário.');
            }

            if ($coupon->store_id !== $store->id) {
                throw new \Exception('Este cupom não pertence à sua loja.');
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

}
