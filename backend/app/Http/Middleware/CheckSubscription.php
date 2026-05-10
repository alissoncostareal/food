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

        // Se o usuário tem uma loja, verificamos o status dela
        if ($user->store) {
            $store = $user->store;

            // Se a assinatura expirou e não é mais trial
            if ($store->subscription_ends_at && now()->gt($store->subscription_ends_at)) {
                return response()->json([
                    'error' => 'Assinatura expirada',
                    'message' => 'Por favor, realize o pagamento do aluguel do sistema para continuar vendendo.'
                ], 402); // 402 = Payment Required
            }
        }

        return $next($request);
    }
}
