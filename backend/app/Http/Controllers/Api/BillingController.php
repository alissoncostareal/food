<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MercadoPagoService;
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
                'error' => 'Erro ao verificar Mercado Pago',
                'details' => $e->getMessage(),
            ], 400);
        }
    }
}
