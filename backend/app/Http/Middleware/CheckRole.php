<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não autenticado.',
                'error' => 'Unauthenticated.',
            ], 401);
        }

        if (!$user->hasRole($roles)) {
            return response()->json([
                'message' => 'Você não tem permissão para acessar este recurso.',
                'error' => 'Role não autorizada.',
                'required_roles' => $roles,
                'current_role' => $user->role,
            ], 403);
        }

        return $next($request);
    }
}
