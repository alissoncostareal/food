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
            $plans = Plan::query()
                ->where('is_active', true)
                ->where('is_visible', true)
                ->orderBy('price')
                ->get();

            return response()->json($plans);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao buscar planos', 'details' => $e->getMessage()], 400);
        }
    }

    public function subscribe(Request $request)
    {
        return response()->json([
            'message' => 'Assinaturas devem ser feitas pelo checkout Pagar.me.',
            'use_endpoint' => '/api/v1/merchant/billing/pagarme/subscription',
        ], 410);
    }
}
