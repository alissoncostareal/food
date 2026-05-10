<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Store;
use Illuminate\Support\Facades\Auth;

class CheckIsStoreOwner
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && (Auth::user()->role === 'admin' || Auth::user()->role === 'store')) {
            return $next($request);
        }

        return response()->json(['error' => 'Acesso negado. Apenas lojistas podem acessar esta rota.'], 403);
    }
}
