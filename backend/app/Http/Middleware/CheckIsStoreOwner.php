<?php

namespace App\Http\Middleware;

use App\Services\MerchantStoreResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckIsStoreOwner
{
    public function __construct(
        private readonly MerchantStoreResolver $merchantStoreResolver
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user?->isMerchantUser()) {
            return response()->json([
                'message' => 'Acesso negado. Apenas lojistas podem acessar esta rota.',
                'error' => 'Role não autorizada.',
            ], 403);
        }

        $store = $this->merchantStoreResolver->resolve($user);

        if (!$store) {
            return response()->json([
                'message' => 'Nenhuma loja vinculada a este usuário.',
                'error' => 'Store not found.',
            ], 403);
        }

        $store->loadMissing('plan');
        $user->setRelation('store', $store);
        $request->attributes->set('merchant_store', $store);

        return $next($request);
    }
}
