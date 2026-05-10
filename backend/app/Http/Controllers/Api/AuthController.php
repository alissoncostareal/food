<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRegisterRequest;
use App\Http\Requests\StoreRegisterRequest;
use App\Models\Store;
use App\Models\User;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Nette\Schema\ValidationException;

class AuthController extends Controller
{
    public function register(CustomerRegisterRequest $request)
    {
        try {
            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
                'role'     => 'customer', // Forçamos a role de cliente aqui
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Usuário criado com sucesso!',
                'data'    => [
                    'token' => $user->createToken('auth_token')->plainTextToken,
                    'user'  => $user->only(['id', 'name', 'email', 'role'])
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao registrar usuário', 'details' => $e->getMessage()], 500);
        }
    }

    // 2. Login de usuários existentes (React Native envia as credenciais)
    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string'
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json(['error' => 'Credenciais inválidas'], 401);
            }

            // Gerar um token de acesso para o usuário autenticado
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Login realizado com sucesso',
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao realizar login', 'details' => $e->getMessage()], 500);
        }
    }

    // 3. Logout (Revoga o token ativo)
    public function logout(Request $request)
    {
        // Apaga o token que fez esta requisição específica
        try {
            $request->user()->currentAccessToken()->delete();
            return response()->json(['message' => 'Logout realizado com sucesso']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao realizar logout', 'details' => $e->getMessage()], 500);
        }
    }

    public function registerStore(StoreRegisterRequest $request)
    {
        try {

            return DB::transaction(function () use ($request) {

                // 1. Criar o Usuário (Dono)
                $user = User::create([
                    'name'     => $request->name,
                    'email'    => $request->email,
                    'password' => Hash::make($request->password),
                    'role'     => 'store_owner'
                ]);

                // 2. Criar a Loja via Relacionamento (Mais limpo e seguro)
                // Isso assume que você tem public function store() no model User
                $store = $user->store()->create([
                    'name'    => $request->store_name,
                    'slug'    => str($request->store_name)->slug(),
                    'is_open' => false,
                ]);

                // 3. Gerar Token de Acesso
                $token = $user->createToken('auth_token')->plainTextToken;

                return response()->json([
                    'status'  => 'success',
                    'message' => 'Lojista e Loja registrados com sucesso!',
                    'data'    => [
                        'token' => $token,
                        'user'  => $user->only(['id', 'name', 'email', 'role']),
                        'store' => $store->only(['id', 'name', 'slug'])
                    ]
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Falha crítica ao registrar lojista.',
                'debug'   => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}
