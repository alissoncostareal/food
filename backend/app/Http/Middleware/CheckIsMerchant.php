<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckIsMerchant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user?->isMerchantUser()) {
            return response()->json([
                'message' => 'Acesso negado. Apenas lojistas podem acessar esta rota.',
                'error' => 'Role não autorizada.',
            ], 403);
        }

        return $next($request);
    }
}
