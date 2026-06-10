<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Throwable;

class BillingController extends Controller
{
    public function mercadoPagoStatus(MercadoPagoService $mercadoPago)
    {
        try {
            return response()->json([
                'mercado_pago' => $mercadoPago->configurationStatus(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Erro ao verificar Mercado Pago.',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function mercadoPagoCheckout(Request $request, MercadoPagoService $mercadoPago)
    {
        try {
            $validated = $request->validate([
                'plan_id' => ['required', 'integer', 'exists:plans,id'],
            ]);

            $user = $request->user();

            $store = $user->store()
                ->with(['plan', 'user'])
                ->firstOrFail();

            $plan = Plan::query()
                ->whereKey($validated['plan_id'])
                ->firstOrFail();

            $checkout = $mercadoPago->createCheckoutPreference($store, $plan);

            return response()->json([
                'message' => 'Checkout criado com sucesso.',
                'preference_id' => $checkout['id'],
                'init_point' => $checkout['init_point'],
                'sandbox_init_point' => $checkout['sandbox_init_point'],
                'external_reference' => $checkout['external_reference'],
                'environment' => $checkout['environment'],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Erro ao criar checkout Mercado Pago.',
                'details' => $e->getMessage(),
            ], 400);
        }
    }
}
