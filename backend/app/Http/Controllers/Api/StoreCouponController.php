<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Store;
use Illuminate\Http\Request;

class StoreCouponController extends Controller
{
    public function validateCoupon(Request $request, Store $store)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'subtotal' => ['required', 'numeric', 'min:0.01'],
        ]);

        $code = strtoupper(trim($validated['code']));
        $subtotal = (float) $validated['subtotal'];

        $coupon = Coupon::where('store_id', $store->id)
            ->where('code', $code)
            ->first();

        if (!$coupon) {
            return response()->json([
                'message' => 'Cupom não encontrado.'
            ], 404);
        }

        if (!$coupon->is_active) {
            return response()->json([
                'message' => 'Este cupom está pausado.'
            ], 422);
        }

        if ($coupon->expires_at && now()->greaterThan($coupon->expires_at)) {
            return response()->json([
                'message' => 'Este cupom expirou.'
            ], 422);
        }

        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            return response()->json([
                'message' => 'Este cupom atingiu o limite de uso.'
            ], 422);
        }

        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            return response()->json([
                'message' => 'O pedido mínimo para este cupom é ' . $this->formatCurrency($coupon->min_order_amount) . '.'
            ], 422);
        }

        $discountAmount = $this->calculateDiscount($coupon, $subtotal);

        if ($discountAmount <= 0) {
            return response()->json([
                'message' => 'Este cupom não gera desconto para este pedido.'
            ], 422);
        }

        return response()->json([
            'message' => 'Cupom aplicado com sucesso.',
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'type' => $coupon->type,
                'value' => (float) $coupon->value,
                'discount_amount' => round($discountAmount, 2),
                'min_order_amount' => $coupon->min_order_amount !== null ? (float) $coupon->min_order_amount : null,
                'max_discount_amount' => $coupon->max_discount_amount !== null ? (float) $coupon->max_discount_amount : null,
            ],
        ]);
    }

    private function calculateDiscount(Coupon $coupon, float $subtotal): float
    {
        if ($coupon->type === 'percentage') {
            $discount = $subtotal * ((float) $coupon->value / 100);

            if ($coupon->max_discount_amount !== null) {
                $discount = min($discount, (float) $coupon->max_discount_amount);
            }

            return min($discount, $subtotal);
        }

        return min((float) $coupon->value, $subtotal);
    }

    private function formatCurrency($value): string
    {
        return 'R$ ' . number_format((float) $value, 2, ',', '.');
    }
}
