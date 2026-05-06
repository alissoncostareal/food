<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

// Rotas públicas (Qualquer um acessa, sem token)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Rotas protegidas (Apenas requisições com o Bearer Token válido entram aqui)
Route::middleware('auth:sanctum')->group(function () {

    // Rota de teste para ver as informações do usuário logado
    Route::get('/me', function (Illuminate\Http\Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);
});
