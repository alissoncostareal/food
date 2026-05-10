<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Auth;
use Illuminate\Http\Request;

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
            $plan = Plan::find($request->plan_id);

            // Aqui no futuro entraria a integração com o Gateway (Stripe/MercadoPago)
            // Por enquanto, vamos simular que ele pagou com sucesso:
            $store->update([
                'plan_id' => $plan->id,
                'subscription_status' => 'active',
                'subscription_ends_at' => now()->addDays(30),
            ]);

            return response()->json([
                'message' => "Plano {$plan->name} assinado com sucesso!",
                'expires_at' => $store->subscription_ends_at->format('d/m/Y H:i')
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao realizar assinatura', 'details' => $e->getMessage()], 400);
        }
    }
}
