<?php

namespace App\Http\Middleware;

use App\Services\MerchantStoreResolver;
use App\Support\ModuleMaintenance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleMaintenance
{
    public function __construct(
        private readonly MerchantStoreResolver $merchantStoreResolver
    ) {
    }

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Usuário não autenticado.',
                'error' => 'Unauthenticated.',
            ], 401);
        }

        $store = $request->attributes->get('merchant_store')
            ?? $this->merchantStoreResolver->resolve($user);

        if (! ModuleMaintenance::isBlocked($module, $store)) {
            return $next($request);
        }

        return response()->json([
            'message' => ModuleMaintenance::messageFor($module),
            'error' => 'module_maintenance',
            'module' => $module,
        ], 503);
    }
}
