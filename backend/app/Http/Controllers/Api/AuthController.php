<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRegisterRequest;
use App\Http\Requests\StoreRegisterRequest;
use App\Http\Resources\StoreResource;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(CustomerRegisterRequest $request)
    {
        try {
            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
                'role'     => 'customer',
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Usuário criado com sucesso!',
                'data'    => [
                    'token' => $user->createToken('auth_token')->plainTextToken,
                    'user'  => $this->formatUserResponse($user),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao registrar usuário',
                'details' => $e->getMessage(),
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

            $user->load('store.plan');

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Login realizado com sucesso',
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $this->formatUserResponse($user),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao realizar login',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function me(Request $request)
    {
        try {
            $user = $request->user();

            $user->load('store.plan');

            return response()->json($this->formatUserResponse($user));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar usuário',
                'details' => $e->getMessage(),
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
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao realizar logout',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function registerStore(StoreRegisterRequest $request)
    {
        try {
            return DB::transaction(function () use ($request) {
                $user = User::create([
                    'name'     => $request->name,
                    'email'    => $request->email,
                    'password' => Hash::make($request->password),
                    'role'     => 'store_owner',
                ]);

                $starterPlan = Plan::where('slug', 'starter')
                    ->where('is_active', true)
                    ->first();

                $store = $user->store()->create([
                    'name' => $request->store_name,
                    'slug' => str($request->store_name)->slug(),
                    'is_open' => false,
                    'plan_id' => $starterPlan?->id,
                    'plan_type' => $starterPlan?->slug ?? 'starter',
                    'subscription_status' => 'trial',
                    'subscription_ends_at' => now()->addDays(7),
                ]);

                $user->load('store.plan');
                $store->load('plan');

                $token = $user->createToken('auth_token')->plainTextToken;

                return response()->json([
                    'status'  => 'success',
                    'message' => 'Lojista e Loja registrados com sucesso!',
                    'data'    => [
                        'token' => $token,
                        'user'  => $this->formatUserResponse($user),
                        'store' => new StoreResource($store),
                    ],
                ], 201);
            });
        } catch (\Exception $e) {
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
            $user->load('store.plan');
        }

        $store = $user->store;

        if ($store && !$store->relationLoaded('plan')) {
            $store->load('plan');
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'store' => $store ? new StoreResource($store) : null,
        ];
    }
}
