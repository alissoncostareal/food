<?php

use App\Http\Controllers\Api\{
    AuthController, OrderController, PlanController,
    ProductCategoryController, ProductController, StoreController,
    StoreOrderController, StoreStatsController
};
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas Públicas
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/plans', [PlanController::class, 'index']);
Route::post('/register/merchant', [AuthController::class, 'registerStore']);

// Catálogo (O que o cliente vê)
Route::get('/stores', [StoreController::class, 'index']);
Route::get('/stores/{store}', [StoreController::class, 'show']);
Route::get('/stores/{store}/products', [ProductController::class, 'indexByStore']);

/*
|--------------------------------------------------------------------------
| Rotas Protegidas (Auth:Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // Perfil e Notificações
    Route::get('/me', fn($request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('notifications')->group(function () {
        Route::get('/', fn() => auth()->user()->unreadNotifications);
        Route::post('/{id}/read', fn($id) => auth()->user()->notifications()->findOrFail($id)->markAsRead());
    });

    // --- FLUXO DO CLIENTE FINAL ---
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
    });

    /*
    |--------------------------------------------------------------------------
    | FLUXO DO LOJISTA (Merchant)
    |--------------------------------------------------------------------------
    */
    Route::middleware('is_store')->group(function () {

        // Assinatura (Acesso para poder regularizar o pagamento)
        Route::post('/subscriptions/subscribe', [PlanController::class, 'subscribe']);

        /*
        | Rotas que exigem Assinatura Ativa (O "Aluguel" do sistema)
        */
        Route::middleware('active_subscription')->group(function () {

            // --- Gestão da Loja & Operação ---
            Route::prefix('store')->group(function () {
                Route::get('/stats', [StoreStatsController::class, 'index']);

                // Rotas que faltavam (Horários e Abrir/Fechar)
                Route::patch('/toggle-open', [StoreController::class, 'toggleOpen']);
                Route::post('/operating-hours', [StoreController::class, 'updateOperatingHours']);

                // CRUD da Loja
                Route::post('/', [StoreController::class, 'store']);
                Route::put('/{id}', [StoreController::class, 'update']);
            });

            // --- Gestão de Pedidos (Painel do Lojista) ---
            Route::prefix('merchant/orders')->group(function () {
                Route::get('/', [StoreOrderController::class, 'index']);
                Route::patch('/{order}/status', [OrderController::class, 'updateStatus']); // Rota unificada
                Route::get('/{order}/print', [OrderController::class, 'print']);
            });

            // --- Gestão de Cardápio ---
            // Categorias
            Route::apiResource('product-categories', ProductCategoryController::class);

            // Produtos
            Route::prefix('products')->group(function () {
                Route::post('/', [ProductController::class, 'store']);
                Route::put('/{id}', [ProductController::class, 'update']);
                Route::delete('/{id}', [ProductController::class, 'destroy']);
                Route::patch('/{id}/toggle-status', [ProductController::class, 'toggleStatus']); // is_available
            });
        });
    });
});
