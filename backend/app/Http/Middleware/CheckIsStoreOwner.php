<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckIsStoreOwner
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isStoreOwner() && $user->store()->exists()) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Acesso negado. Apenas lojistas podem acessar esta rota.',
            'error' => 'Role não autorizada.',
        ], 403);
    }
}
