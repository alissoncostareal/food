<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('store.{storeId}', function ($user, $storeId) {
    // Se o erro 403 persistir com 'return true', o problema é a configuração do Sanctum acima.
    return (int) $user->store_id === (int) $storeId;
}, ['guards' => ['sanctum']]);
