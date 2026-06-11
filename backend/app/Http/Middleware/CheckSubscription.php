<?php

namespace App\Http\Middleware;

use App\Services\MerchantStoreResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    public function __construct(
        private readonly MerchantStoreResolver $merchantStoreResolver
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não autenticado.',
                'error' => 'Unauthenticated.',
            ], 401);
        }

        $store = $request->attributes->get('merchant_store')
            ?? $this->merchantStoreResolver->resolve($user);

        if (!$store) {
            return response()->json([
                'message' => 'Loja não configurada.',
                'error' => 'Store not found.',
            ], 404);
        }

        $store->ensureSubscriptionStateIsCurrent();

        if (!$store->hasActiveSubscription()) {
            return response()->json([
                'message' => 'Sua assinatura não está ativa.',
                'error' => 'Assinatura inativa.',
                'subscription_status' => $store->subscription_status,
                'upgrade_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
