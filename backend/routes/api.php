<?php

use App\Http\Controllers\Api\{
    AuthController, CheckoutController, CustomerController, MerchantCouponController, OrderController, PlanController,
    ProductCategoryController, ProductController, StoreController,
    StoreCouponController,
    StoreOrderController, StoreStatsController
};
use App\Http\Controllers\Api\Merchant\OptionGroupController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas Públicas (Sem Autenticação)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register/merchant', [AuthController::class, 'registerStore']);

    Route::get('/plans', [PlanController::class, 'index']);
    Route::get('/stores', [StoreController::class, 'index']);
    Route::get('/stores/{store:slug}', [StoreController::class, 'showBySlug']);
    Route::get('/stores/{store}/products', [ProductController::class, 'indexByStore']);

    Route::post('/customers/send-code', [CustomerController::class, 'sendCode']);
    Route::post('/customers/verify-code', [CustomerController::class, 'verifyCode']);
    Route::post('/customers/whatsapp/find-or-create', [CustomerController::class, 'findOrCreateByWhatsapp']);
    Route::post('/customers/whatsapp/show', [CustomerController::class, 'showByWhatsapp']);
    Route::post('/checkout/orders', [CheckoutController::class, 'store']);

    Route::post('/stores/{store:slug}/coupons/validate', [StoreCouponController::class, 'validateCoupon']);
    Route::get('/stores/{store:slug}/delivery-areas', function (\App\Models\Store $store) {
        return response()->json([
            'data' => $store->deliveryAreas()
                ->where('is_active', true)
                ->orderBy('district_name')
                ->get()
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Rotas Protegidas (Auth:Sanctum)
|--------------------------------------------------------------------------
*/
Broadcast::routes(['middleware' => ['auth.sanctum']]);
Route::middleware('auth:sanctum')->prefix('v1')->group(function () {

    Route::get('/customer/profile', [CustomerController::class, 'profile']);
    Route::get('/customer/orders', [CustomerController::class, 'orders']);
    Route::put('/customer/profile', [CustomerController::class, 'updateProfile']);

    Route::get('/me', function () {
        $user = auth()->user();
        if ($user && method_exists($user, 'store')) {
            $user->load('store');
        }
        return response()->json($user);
    });
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('notifications')->group(function () {
        Route::get('/', fn() => auth()->user()->unreadNotifications);
        Route::patch('/{id}/read', fn($id) => auth()->user()->notifications()->findOrFail($id)->markAsRead());
    });

    /*
    |--------------------------------------------------------------------------
    | PEDIDOS DO CLIENTE (Consumidor Final)
    |--------------------------------------------------------------------------
    | Aqui fica a criação dos pedidos (POST).
    | Aplicado o throttle para travar cliques repetidos (máximo 3 tentativas por minuto).
    */
    Route::prefix('orders')->group(function () {
        Route::post('/', [OrderController::class, 'store'])
            ->middleware('throttle:3,1');

        Route::get('/{order}', [OrderController::class, 'show']);
    });

    /*
    |--------------------------------------------------------------------------
    | PAINEL DO LOJISTA (Merchant Dashboard)
    |--------------------------------------------------------------------------
    */
    Route::middleware('is_store')->prefix('merchant')->group(function () {

        Route::prefix('store')->group(function () {
            Route::get('/', [StoreController::class, 'me']);
            Route::post('/update', [StoreController::class, 'updateSettings']);
        });

        Route::post('/subscribe', [PlanController::class, 'subscribe']);

        Route::middleware('active_subscription')->group(function () {
            Route::get('/stats', [StoreStatsController::class, 'index']);
            Route::patch('/toggle-open', [StoreController::class, 'toggleOpen']);
            Route::post('/operating-hours', [StoreController::class, 'updateOperatingHours']);

            Route::prefix('orders')->group(function () {
                Route::get('/', [StoreOrderController::class, 'index']);
                Route::get('/{order}', [StoreOrderController::class, 'show']);
                Route::patch('/{order}/status', [OrderController::class, 'updateStatus']);
                Route::get('/{order}/print', [OrderController::class, 'print']);
            });

            Route::apiResource('coupons', MerchantCouponController::class);
            Route::patch('coupons/{coupon}/toggle', [MerchantCouponController::class, 'toggle']);

            Route::put('/categories/reorder', [ProductCategoryController::class, 'reorder']);
            Route::apiResource('categories', ProductCategoryController::class);
            Route::apiResource('products', ProductController::class);
            Route::patch('products/{id}/toggle-status', [ProductController::class, 'toggleStatus']);

            Route::prefix('products/{product}')->group(function () {
                Route::post('/option-groups', [OptionGroupController::class, 'store']);
                Route::put('/option-groups/{group}', [OptionGroupController::class, 'update']);
                Route::delete('/option-groups/{group}', [OptionGroupController::class, 'destroy']);
            });
        });
    });
});
