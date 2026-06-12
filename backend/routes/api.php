<?php

use App\Http\Controllers\Api\{
    AuthController,
    BillingController,
    CatalogImportController,
    CheckoutController,
    CheckoutCardController,
    CheckoutPaymentController,
    CustomerController,
    DeliveryAreaController,
    GeocodingController,
    IfoodIntegrationController,
    MerchantCouponController,
    MerchantPaymentController,
    OptionGroupController,
    OrderController,
    PasswordResetController,
    PaymentWebhookController,
    PlanController,
    ProductCategoryController,
    ProductController,
    SalesReportController,
    StoreController,
    StoreCouponController,
    StoreOrderController,
    StoreStatsController,
    StoreIntelligenceController,
    StoreTeamController,
    SuperAdminController,
    UserPreferenceController,
    WhatsappIntegrationController
};
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:5,1');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:10,1');
    Route::post('/register/merchant', [AuthController::class, 'registerStore'])
        ->middleware('throttle:5,1');

    Route::get('/team/invitations/{token}', [StoreTeamController::class, 'showInvitation']);
    Route::post('/team/invitations/{token}/accept', [StoreTeamController::class, 'acceptInvitation']);

    Route::get('/plans', [PlanController::class, 'index']);
    Route::get('/stores', [StoreController::class, 'index']);
    Route::get('/stores/{store:slug}', [StoreController::class, 'showBySlug']);
    Route::get('/stores/{store}/products', [ProductController::class, 'indexByStore']);

    Route::post('/customers/send-code', [CustomerController::class, 'sendCode'])
        ->middleware('throttle:5,1');
    Route::post('/customers/verify-code', [CustomerController::class, 'verifyCode']);
    Route::post('/customers/whatsapp/find-or-create', [CustomerController::class, 'findOrCreateByWhatsapp']);
    Route::post('/customers/whatsapp/show', [CustomerController::class, 'showByWhatsapp'])
        ->middleware('throttle:10,1');

    Route::post('/checkout/orders', [CheckoutController::class, 'store'])
        ->middleware('throttle:5,1');

    Route::post('/checkout/card-token', [CheckoutCardController::class, 'token'])
        ->middleware('throttle:10,1');

    Route::get('/checkout/orders/{order}/payment', [CheckoutPaymentController::class, 'show'])
        ->middleware('throttle:30,1');

    Route::get('/geocoding/search', [GeocodingController::class, 'search'])
        ->middleware('throttle:30,1');
    Route::get('/geocoding/cep', [GeocodingController::class, 'cep'])
        ->middleware('throttle:30,1');
    Route::get('/geocoding/reverse', [GeocodingController::class, 'reverse'])
        ->middleware('throttle:30,1');

    Route::post('/stores/{store:slug}/coupons/validate', [StoreCouponController::class, 'validateCoupon']);

    Route::post('/billing/pagarme/webhook', [BillingController::class, 'pagarMeWebhook']);
    Route::post('/webhooks/payments/{provider}/{store:slug}', [PaymentWebhookController::class, 'handle'])
        ->middleware('throttle:120,1');
    Route::post('/integrations/ifood/webhook', [IfoodIntegrationController::class, 'webhook'])
        ->middleware('throttle:120,1');
    Route::post('/webhooks/evolution/{store:slug}', [WhatsappIntegrationController::class, 'webhook'])
        ->middleware('throttle:120,1');

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

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('customer')->group(function () {
        Route::get('/profile', [CustomerController::class, 'profile']);
        Route::get('/orders', [CustomerController::class, 'orders']);
        Route::put('/profile', [CustomerController::class, 'updateProfile']);
    });

    Route::middleware('role:super_admin')->prefix('super-admin')->group(function () {
        Route::get('/settings', [SuperAdminController::class, 'settings']);
        Route::put('/settings', [SuperAdminController::class, 'updateSettings']);
        Route::get('/summary', [SuperAdminController::class, 'summary']);
        Route::get('/plans', [SuperAdminController::class, 'plans']);
        Route::put('/plans/{plan}', [SuperAdminController::class, 'updatePlan']);
        Route::get('/stores', [SuperAdminController::class, 'stores']);
        Route::patch('/stores/{store}/courtesy', [SuperAdminController::class, 'grantCourtesy']);
        Route::patch('/stores/{store}/subscription', [SuperAdminController::class, 'updateSubscription']);
        Route::patch('/stores/{store}/detach-branch', [SuperAdminController::class, 'detachBranch']);
        Route::post('/integrations/ifood/test-credentials', [SuperAdminController::class, 'testIfoodCredentials']);
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

        Route::middleware('store_owner_only')->group(function () {
            Route::post('/subscribe', [PlanController::class, 'subscribe']);

            Route::prefix('billing')->group(function () {
                Route::post('/pagarme/token', [BillingController::class, 'pagarMeToken']);
                Route::post('/pagarme/subscription', [BillingController::class, 'pagarMeSubscription']);
                Route::get('/pagarme/status', [BillingController::class, 'pagarMeStatus']);
            });

            Route::prefix('payments')->group(function () {
                Route::get('/connection', [MerchantPaymentController::class, 'connection']);
                Route::put('/settings', [MerchantPaymentController::class, 'updateSettings']);
                Route::post('/providers/{provider}', [MerchantPaymentController::class, 'saveProvider']);
                Route::post('/providers/{provider}/activate', [MerchantPaymentController::class, 'activateProvider']);
                Route::delete('/providers/{provider}', [MerchantPaymentController::class, 'disconnectProvider']);
            });

            Route::prefix('stores')->group(function () {
                Route::get('/branches', [StoreController::class, 'listBranches']);
                Route::post('/branches', [StoreController::class, 'createBranch'])
                    ->middleware('throttle:10,1');
            });
        });

        Route::get('/stats', [StoreStatsController::class, 'index']);

        Route::middleware('active_subscription')->group(function () {
            Route::middleware('feature:intelligence')->get('/intelligence', [StoreIntelligenceController::class, 'show']);

            Route::middleware('store_owner_only')->middleware('feature:team')->prefix('team')->group(function () {
                Route::get('/', [StoreTeamController::class, 'index']);
                Route::post('/members', [StoreTeamController::class, 'storeMember']);
                Route::post('/invitations', [StoreTeamController::class, 'invite'])
                    ->middleware('throttle:10,1');
                Route::delete('/members/{member}', [StoreTeamController::class, 'destroyMember']);
                Route::delete('/invitations/{invitation}', [StoreTeamController::class, 'cancelInvitation']);
            });

            Route::patch('/toggle-open', [StoreController::class, 'toggleOpen']);
            Route::post('/operating-hours', [StoreController::class, 'updateOperatingHours']);

            Route::middleware('feature:advanced_reports')->prefix('reports')->group(function () {
                Route::get('/sales/monthly', [SalesReportController::class, 'exportMonthly']);
            });

            Route::prefix('orders')->group(function () {
                Route::get('/', [OrderController::class, 'index']);
                Route::get('/{order}', [StoreOrderController::class, 'show']);
                Route::get('/{order}/ifood/cancellation-reasons', [OrderController::class, 'ifoodCancellationReasons']);
                Route::patch('/{order}/status', [OrderController::class, 'updateStatus']);
                Route::get('/{order}/print', [OrderController::class, 'print']);
            });

            Route::middleware('feature:coupons')->group(function () {
                Route::apiResource('coupons', MerchantCouponController::class);
                Route::patch('coupons/{coupon}/toggle', [MerchantCouponController::class, 'toggle']);
            });

            Route::middleware('feature:delivery_areas')->group(function () {
                Route::get('delivery-areas/map-preview', [DeliveryAreaController::class, 'mapPreview']);
                Route::apiResource('delivery-areas', DeliveryAreaController::class)
                    ->parameters(['delivery-areas' => 'deliveryArea'])
                    ->except(['show']);
                Route::patch('delivery-areas/{deliveryArea}/toggle', [DeliveryAreaController::class, 'toggle']);
            });

            Route::middleware('store_owner_only')->middleware('feature:ifood_integration')->prefix('import/catalog')->group(function () {
                Route::get('/sample', [CatalogImportController::class, 'sample']);
                Route::post('/preview', [CatalogImportController::class, 'preview']);
                Route::post('/xml', [CatalogImportController::class, 'import']);
            });

            Route::middleware('store_owner_only')->middleware('feature:ifood_integration')->prefix('integrations/ifood')->group(function () {
                Route::get('/status', [IfoodIntegrationController::class, 'status']);
                Route::get('/connection', [IfoodIntegrationController::class, 'connection']);
                Route::put('/connection', [IfoodIntegrationController::class, 'updateConnection']);
                Route::post('/oauth/user-code', [IfoodIntegrationController::class, 'createUserCode']);
                Route::post('/oauth/exchange', [IfoodIntegrationController::class, 'exchangeAuthorizationCode']);
                Route::get('/oauth/merchants', [IfoodIntegrationController::class, 'authorizedMerchants']);
                Route::post('/connection/test', [IfoodIntegrationController::class, 'testConnection']);
                Route::post('/connection/disconnect', [IfoodIntegrationController::class, 'disconnect']);
                Route::put('/settings', [IfoodIntegrationController::class, 'updateSettings']);
                Route::post('/catalog/import', [IfoodIntegrationController::class, 'importCatalog']);
                Route::get('/sales', [IfoodIntegrationController::class, 'sales']);
                Route::post('/catalog/seed-sandbox', [IfoodIntegrationController::class, 'seedSandboxCatalog']);
            });

            Route::middleware('feature:whatsapp_auto')->prefix('integrations/whatsapp')->group(function () {
                Route::get('/status', [WhatsappIntegrationController::class, 'status']);
                Route::get('/connection', [WhatsappIntegrationController::class, 'connection']);
                Route::get('/messages', [WhatsappIntegrationController::class, 'messages']);
                Route::get('/bot', [WhatsappIntegrationController::class, 'botSettings']);

                Route::middleware('store_owner_only')->group(function () {
                    Route::post('/provision', [WhatsappIntegrationController::class, 'provision']);
                    Route::post('/sync', [WhatsappIntegrationController::class, 'syncConnection']);
                    Route::get('/qrcode', [WhatsappIntegrationController::class, 'qrcode']);
                    Route::post('/test-message', [WhatsappIntegrationController::class, 'sendTestMessage']);
                    Route::put('/messages', [WhatsappIntegrationController::class, 'updateMessages']);
                    Route::put('/bot', [WhatsappIntegrationController::class, 'updateBotSettings']);
                });
            });

            Route::put('/categories/reorder', [ProductCategoryController::class, 'reorder']);
            Route::apiResource('categories', ProductCategoryController::class);

            Route::apiResource('products', ProductController::class);
            Route::patch('products/{id}/toggle-status', [ProductController::class, 'toggleStatus']);
            Route::patch('products/{id}/toggle-cart-highlight', [ProductController::class, 'toggleCartHighlight']);

            Route::prefix('products/{product}')->group(function () {
                Route::post('/option-groups', [OptionGroupController::class, 'store']);
                Route::match(['put', 'post'], '/option-groups/{group}', [OptionGroupController::class, 'update']);
                Route::delete('/option-groups/{group}', [OptionGroupController::class, 'destroy']);
            });
        });
    });

    Route::middleware('is_merchant')->prefix('merchant')->group(function () {
        Route::get('/onboarding/status', [StoreController::class, 'onboardingStatus']);
        Route::post('/store/create', [StoreController::class, 'createMatriz'])
            ->middleware(['store_owner_only', 'throttle:5,1']);
        Route::get('/preferences', [UserPreferenceController::class, 'show']);
        Route::patch('/preferences', [UserPreferenceController::class, 'update']);
        Route::get('/stores/accessible', [StoreController::class, 'listAccessible']);
        Route::post('/stores/switch', [StoreController::class, 'switchStore'])
            ->middleware('throttle:20,1');
    });
});
