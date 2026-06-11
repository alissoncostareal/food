<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\OrderPixPaymentService;
use Illuminate\Http\Request;
use Throwable;

class CheckoutCardController extends Controller
{
    public function token(Request $request, OrderPixPaymentService $payments)
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'number' => ['required', 'string', 'min:13', 'max:19'],
            'holder_name' => ['required', 'string', 'max:255'],
            'holder_document' => ['required', 'string', 'min:11', 'max:14'],
            'exp_month' => ['required', 'integer', 'min:1', 'max:12'],
            'exp_year' => ['required', 'integer', 'min:24', 'max:2099'],
            'cvv' => ['required', 'string', 'min:3', 'max:4'],
        ]);

        try {
            $store = Store::findOrFail($validated['store_id']);

            if (! $payments->storeAcceptsCardOnline($store)) {
                return response()->json([
                    'message' => 'Cartão online não está disponível nesta loja.',
                ], 422);
            }

            $token = $payments->createCardToken($store, $validated);

            return response()->json([
                'token' => $token,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Não foi possível validar o cartão.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 422);
        }
    }
}
