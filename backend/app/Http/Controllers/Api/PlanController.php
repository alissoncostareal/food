<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\PlanLaunchPricingService;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanController extends Controller
{
    public function __construct(
        private readonly PlanLaunchPricingService $launchPricing
    ) {
    }

    public function index()
    {
        try {
            $plans = Plan::query()
                ->where('is_active', true)
                ->where('is_visible', true)
                ->orderBy('price')
                ->get()
                ->map(fn (Plan $plan) => $this->presentPlan($plan));

            return response()->json($plans);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao buscar planos', 'details' => $e->getMessage()], 400);
        }
    }

    /**
     * Planos para a landing pública: inclui ocultos (is_visible=false) para exibição desativada.
     */
    public function landing()
    {
        try {
            $plans = Plan::query()
                ->where('is_active', true)
                ->orderBy('price')
                ->get()
                ->map(fn (Plan $plan) => $this->presentPlan($plan));

            return response()->json($plans);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao buscar planos', 'details' => $e->getMessage()], 400);
        }
    }

    private function presentPlan(Plan $plan): array
    {
        $launch = $this->launchPricing->planPresentation($plan);

        return array_merge($plan->toArray(), $launch, [
            'price' => $launch['display_price'],
            'regular_price' => $launch['regular_price'],
        ]);
    }

    public function subscribe(Request $request)
    {
        return response()->json([
            'message' => 'Assinaturas devem ser feitas pelo checkout Pagar.me.',
            'use_endpoint' => '/api/v1/merchant/billing/pagarme/subscription',
        ], 410);
    }
}
