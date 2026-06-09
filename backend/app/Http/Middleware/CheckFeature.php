<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não autenticado.',
                'error' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->role === 'super_admin') {
            return $next($request);
        }

        $store = $user->store()->with('plan')->first();

        if (!$store) {
            return response()->json([
                'message' => 'Loja não configurada.',
                'error' => 'Store not found.',
            ], 404);
        }

        if (!$store->hasActiveSubscription()) {
            return response()->json([
                'message' => 'Sua assinatura não está ativa.',
                'error' => 'Assinatura inativa.',
                'subscription_status' => $store->subscription_status,
                'upgrade_required' => true,
            ], 403);
        }

        if (!$store->hasFeature($feature)) {
            return response()->json([
                'message' => 'Este recurso não está disponível no seu plano.',
                'error' => 'Feature não disponível.',
                'feature' => $feature,
                'plan' => $store->plan ? [
                    'id' => $store->plan->id,
                    'name' => $store->plan->name,
                    'slug' => $store->plan->slug,
                ] : null,
                'upgrade_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
