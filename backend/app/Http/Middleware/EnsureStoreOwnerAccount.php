<?php

namespace App\Http\Middleware;

use App\Services\MerchantStoreResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStoreOwnerAccount
{
    public function __construct(
        private readonly MerchantStoreResolver $merchantStoreResolver
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user?->isStoreOwner()) {
            return response()->json([
                'message' => 'Apenas o dono da loja pode executar esta ação.',
                'error' => 'Owner required.',
            ], 403);
        }

        $store = $request->attributes->get('merchant_store')
            ?? $this->merchantStoreResolver->resolve($user);

        if ($store && !$user->ownsStore($store)) {
            return response()->json([
                'message' => 'Você não tem permissão para alterar dados de cobrança desta loja.',
                'error' => 'Store ownership required.',
            ], 403);
        }

        return $next($request);
    }
}
