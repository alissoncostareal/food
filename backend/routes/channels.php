<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('store.{storeId}', function ($user, $storeId) {
    $authorized = (int) $user->store?->id === (int) $storeId;

    Log::info('Resultado da autorização:', ['authorized' => $authorized]);

    return $authorized;
}, ['guards' => ['sanctum']]);
