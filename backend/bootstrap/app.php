<?php

use App\Http\Middleware\CheckFeature;
use App\Http\Middleware\CheckModuleMaintenance;
use App\Http\Middleware\CheckIsMerchant;
use App\Http\Middleware\CheckIsStoreOwner;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckSubscription;
use App\Http\Middleware\EnsureStoreOwnerAccount;
use App\Support\ThrottleResponse;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->statefulApi();

        $middleware->alias([
            'feature' => CheckFeature::class,
            'module' => CheckModuleMaintenance::class,
            'is_merchant' => CheckIsMerchant::class,
            'is_store' => CheckIsStoreOwner::class,
            'store_owner_only' => EnsureStoreOwnerAccount::class,
            'role' => CheckRole::class,
            'active_subscription' => CheckSubscription::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'broadcasting/auth',
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            return true;
        });

        $exceptions->render(function (MissingAppKeyException $e, $request) {
            return response()->json([
                'message' => 'Servidor sem APP_KEY. No backend, rode: php artisan key:generate --force',
            ], 500);
        });

        $exceptions->render(function (DecryptException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Falha ao ler dado criptografado. Verifique se APP_KEY está correto no servidor.',
            ], 500);
        });

        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ThrottleResponse::fromException($e);
        });
    })->create();
