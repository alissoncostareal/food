<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanController extends Controller
{
    public function index()
    {
        try {
            $plans = Plan::all();
            return response()->json($plans);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao buscar planos', 'details' => $e->getMessage()], 400);
        }
    }

    public function subscribe(Request $request)
    {
        try {

            $request->validate([
                'plan_id' => 'required|exists:plans,id'
            ]);

            $user = Auth::user();
            $store = $user->store;

            if (!$store) {
                return response()->json(['error' => 'Loja não encontrada para este usuário.'], 404);
            }

            $subscription = DB::transaction(function () use ($request, $store) {
                $plan = Plan::findOrFail($request->plan_id);
                $currentPlan = $store->plan;

                if ($currentPlan && (int) $currentPlan->id === (int) $plan->id) {
                    return [
                        'status' => 'same_plan',
                        'plan' => $plan,
                        'store' => $store,
                    ];
                }

                if ($currentPlan && (float) $plan->price < (float) $currentPlan->price) {
                    return [
                        'status' => 'downgrade_blocked',
                        'plan' => $plan,
                        'store' => $store,
                    ];
                }

                // Aqui no futuro entrará a criação da assinatura no Mercado Pago.
                $store->update([
                    'plan_id' => $plan->id,
                    'plan_type' => $plan->slug,
                    'subscription_status' => 'active',
                    'subscription_ends_at' => now()->addDays(30),
                ]);

                return [
                    'status' => 'subscribed',
                    'plan' => $plan,
                    'store' => $store->fresh(),
                ];
            });

            if ($subscription['status'] === 'same_plan') {
                return response()->json([
                    'message' => "Sua loja já está no plano {$subscription['plan']->name}.",
                    'already_subscribed' => true,
                ]);
            }

            if ($subscription['status'] === 'downgrade_blocked') {
                return response()->json([
                    'message' => 'Para reduzir o plano, fale com o suporte para evitar perda de recursos importantes.',
                    'downgrade_requires_support' => true,
                ], 422);
            }

            $expiryDate = $subscription['store']->subscription_ends_at;
            return response()->json([
                'message' => "Plano {$subscription['plan']->name} assinado com sucesso!",
                // Verificamos se a data existe antes de formatar para não dar erro 500
                'expires_at' => $expiryDate ? $expiryDate->format('d/m/Y H:i') : null
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao realizar assinatura', 'details' => $e->getMessage()], 400);
        }
    }
}
