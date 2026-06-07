<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Usamos store() com parênteses para forçar uma nova consulta ao banco
        $store = $user->store()->first();

        if ($store) {
            // 1. Bloqueia se o status não for 'active'
            $allowedStatuses = ['active', 'trial'];

            if (!in_array($store->subscription_status, $allowedStatuses)) {
                return response()->json([
                    'error' => 'Assinatura Pendente',
                    'message' => 'Selecione um plano para ativar sua loja.'
                ], 403);
            }

            // 2. Bloqueia se a assinatura expirou
            // O Laravel precisa que 'subscription_ends_at' seja um objeto Carbon (use o $casts no Model Store)
            if ($store->subscription_ends_at && now()->gt($store->subscription_ends_at)) {
                return response()->json([
                    'error' => 'Assinatura expirada',
                    'message' => 'Por favor, renove sua assinatura para continuar vendendo.'
                ], 402);
            }
        } else {
            // Se o cara é 'is_store' mas não tem loja criada, barramos também
            return response()->json(['error' => 'Loja não configurada.'], 403);
        }

        return $next($request);
    }
}
