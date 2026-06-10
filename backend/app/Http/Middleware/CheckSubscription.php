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

        if (!$store) {
            return response()->json(['error' => 'Loja não configurada.'], 403);
        }

        if (!$store->hasActiveSubscription()) {
            return response()->json([
                'error' => 'Assinatura inativa',
                'message' => 'Selecione ou renove um plano para continuar vendendo.',
                'subscription_status' => $store->subscription_status,
                'upgrade_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
