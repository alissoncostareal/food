<?php

use App\Http\Middleware\CheckIsStoreOwner;
use App\Http\Middleware\CheckSubscription;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
            'is_store' => CheckIsStoreOwner::class,
             'active_subscription' => CheckSubscription::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            return true;
        });
    })->create();
