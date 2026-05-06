<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Nette\Schema\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8',
                'role' => 'required|in:customer,store_owner'
            ]);

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => $request->role
            ]);

            return response()->json(['message' => 'Usuário registrado com sucesso', 'user' => $user], 201);
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
}
