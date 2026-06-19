<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRegisterRequest;
use App\Http\Requests\StoreRegisterRequest;
use App\Http\Resources\StoreResource;
use App\Models\Plan;
use App\Models\User;
use App\Support\ModuleMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Throwable;

class AuthController extends Controller
{
    public function register(CustomerRegisterRequest $request)
    {
        try {
            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
                'role'     => User::ROLE_CUSTOMER,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Usuário criado com sucesso!',
                'data'    => [
                    'token' => $user->createToken('auth_token')->plainTextToken,
                    'user'  => $this->formatUserResponse($user),
                ],
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao registrar usuário',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => ['required', 'string', 'email'],
                'password' => ['required', 'string'],
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'error' => 'Credenciais inválidas',
                ], 401);
            }

            $user->load(['store.plan', 'storeMemberships.store.plan', 'currentStore.plan']);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Login realizado com sucesso',
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $this->formatUserResponse($user),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao realizar login',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function me(Request $request)
    {
        try {
            $user = $request->user();

            $user->load(['store.plan', 'storeMemberships.store.plan', 'currentStore.plan']);

            return response()->json($this->formatUserResponse($user));
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao carregar usuário',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Logout realizado com sucesso',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao realizar logout',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function registerStore(StoreRegisterRequest $request)
    {
        try {
            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
                'role'     => User::ROLE_STORE_OWNER,
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status'  => 'success',
                'message' => 'Conta criada com sucesso! Complete o cadastro da sua loja matriz.',
                'data'    => [
                    'token' => $token,
                    'user'  => $this->formatUserResponse($user),
                ],
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Falha crítica ao registrar lojista.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function formatUserResponse(User $user): array
    {
        if (!$user->relationLoaded('store')) {
            $user->load(['store.plan', 'storeMemberships.store.plan', 'currentStore.plan']);
        }

        $store = $user->resolveMerchantStore();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'store' => $store ? new StoreResource($store) : null,
            'needs_onboarding' => $user->needsStoreOnboarding(),
            'permissions' => [
                'is_super_admin' => $user->isSuperAdmin(),
                'is_store_owner' => $user->isStoreOwner(),
                'is_store_staff' => $user->isStoreStaff(),
                'can_manage_team' => $store ? $user->canManageStoreTeam($store) : false,
                'can_manage_billing' => $store ? $user->ownsStore($store) : $user->isStoreOwner(),
                'can_manage_platform' => $user->hasRole([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN]),
            ],
            'module_maintenance' => ModuleMaintenance::activeModulesForStore($store),
        ];
    }
}
