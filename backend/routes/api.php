<?php

use App\Http\Controllers\Api\{
    AuthController,
    BillingController,
    CheckoutController,
    CustomerController,
    DeliveryAreaController,
    MerchantCouponController,
    OptionGroupController,
    OrderController,
    PlanController,
    ProductCategoryController,
    ProductController,
    SalesReportController,
    StoreController,
    StoreCouponController,
    StoreOrderController,
    StoreStatsController,
    SuperAdminController
};
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

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

    Route::post('/checkout/orders', [CheckoutController::class, 'store'])
        ->middleware('throttle:5,1');

    Route::post('/stores/{store:slug}/coupons/validate', [StoreCouponController::class, 'validateCoupon']);

    Route::post('/billing/mercado-pago/webhook', [BillingController::class, 'mercadoPagoWebhook']);

    Route::get('/stores/{store:slug}/delivery-areas', function (\App\Models\Store $store) {
        if (!$store->canUseFeature('delivery_areas')) {
            return response()->json([
                'data' => [],
            ]);
        }

        return response()->json([
            'data' => $store->deliveryAreas()
                ->where('is_active', true)
                ->orderBy('district_name')
                ->get(),
        ]);
    });
});

Broadcast::routes(['middleware' => ['auth.sanctum']]);

Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('customer')->group(function () {
        Route::get('/profile', [CustomerController::class, 'profile']);
        Route::get('/orders', [CustomerController::class, 'orders']);
        Route::put('/profile', [CustomerController::class, 'updateProfile']);
    });

    Route::middleware('role:super_admin')->prefix('super-admin')->group(function () {
        Route::get('/summary', [SuperAdminController::class, 'summary']);
        Route::get('/plans', [SuperAdminController::class, 'plans']);
        Route::put('/plans/{plan}', [SuperAdminController::class, 'updatePlan']);

        Route::get('/stores', [SuperAdminController::class, 'stores']);
        Route::patch('/stores/{store}/courtesy', [SuperAdminController::class, 'grantCourtesy']);
        Route::patch('/stores/{store}/subscription', [SuperAdminController::class, 'updateSubscription']);
    });

    Route::prefix('notifications')->group(function () {
        Route::get('/', fn () => auth()->user()->unreadNotifications);
        Route::patch('/{id}/read', fn ($id) => auth()->user()->notifications()->findOrFail($id)->markAsRead());
    });

    Route::prefix('orders')->group(function () {
        Route::post('/', [OrderController::class, 'store'])
            ->middleware('throttle:3,1');

        Route::get('/{order}', [OrderController::class, 'show']);
    });

    Route::middleware('is_store')->prefix('merchant')->group(function () {
        Route::prefix('store')->group(function () {
            Route::get('/', [StoreController::class, 'me']);
            Route::post('/update', [StoreController::class, 'updateSettings']);
        });

        Route::post('/subscribe', [PlanController::class, 'subscribe']);

        Route::prefix('billing')->group(function () {
            Route::get('/mercado-pago/status', [BillingController::class, 'mercadoPagoStatus']);
            Route::post('/mercado-pago/checkout', [BillingController::class, 'mercadoPagoCheckout']);
        });

        Route::middleware('active_subscription')->group(function () {
            Route::get('/stats', [StoreStatsController::class, 'index']);
            Route::patch('/toggle-open', [StoreController::class, 'toggleOpen']);
            Route::post('/operating-hours', [StoreController::class, 'updateOperatingHours']);

            Route::middleware('feature:advanced_reports')->prefix('reports')->group(function () {
                Route::get('/sales/monthly', [SalesReportController::class, 'exportMonthly']);
            });

            Route::prefix('orders')->group(function () {
                Route::get('/', [StoreOrderController::class, 'index']);
                Route::get('/{order}', [StoreOrderController::class, 'show']);
                Route::patch('/{order}/status', [OrderController::class, 'updateStatus']);
                Route::get('/{order}/print', [OrderController::class, 'print']);
            });

            Route::middleware('feature:coupons')->group(function () {
                Route::apiResource('coupons', MerchantCouponController::class);
                Route::patch('coupons/{coupon}/toggle', [MerchantCouponController::class, 'toggle']);
            });

            Route::middleware('feature:delivery_areas')->group(function () {
                Route::apiResource('delivery-areas', DeliveryAreaController::class)
                    ->parameters(['delivery-areas' => 'deliveryArea'])
                    ->except(['show']);
                Route::patch('delivery-areas/{deliveryArea}/toggle', [DeliveryAreaController::class, 'toggle']);
            });

            Route::put('/categories/reorder', [ProductCategoryController::class, 'reorder']);
            Route::apiResource('categories', ProductCategoryController::class);

            Route::apiResource('products', ProductController::class);
            Route::patch('products/{id}/toggle-status', [ProductController::class, 'toggleStatus']);

            Route::prefix('products/{product}')->group(function () {
                Route::post('/option-groups', [OptionGroupController::class, 'store']);
                Route::match(['put', 'post'], '/option-groups/{group}', [OptionGroupController::class, 'update']);
                Route::delete('/option-groups/{group}', [OptionGroupController::class, 'destroy']);
            });
        });
    });
});
