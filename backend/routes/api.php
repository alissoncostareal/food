<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StoreController;
use Illuminate\Support\Facades\Route;

// Rotas públicas (Qualquer um acessa, sem token)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/stores', [StoreController::class, 'index']);
Route::get('/stores/{store}', [StoreController::class, 'show']);

// Rotas protegidas (Apenas requisições com o Bearer Token válido entram aqui)
Route::middleware('auth:sanctum')->group(function () {

    // Rota de teste para ver as informações do usuário logado
    Route::get('/me', function (Illuminate\Http\Request $request) {
        return $request->user();
    });

    Route::post('/stores', [StoreController::class, 'store']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
