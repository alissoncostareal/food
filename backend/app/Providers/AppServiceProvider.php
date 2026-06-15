<?php

namespace App\Providers;

use App\Events\NewOrderPlaced;
use App\Events\OrderUpdated;
use App\Listeners\WhatsApp\NotifyCustomerOnNewOrder;
use App\Listeners\WhatsApp\NotifyCustomerOnOrderStatusChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        Event::listen(NewOrderPlaced::class, NotifyCustomerOnNewOrder::class);
        Event::listen(OrderUpdated::class, NotifyCustomerOnOrderStatusChanged::class);
    }
}
