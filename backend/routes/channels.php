<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('store.{storeId}', function ($user, $storeId) {
    Log::info("Autorizando canal", ['user_id' => $user->id, 'store_id' => $user->store_id, 'requested' => $storeId]);
    return (int) $user->store_id === (int) $storeId;
}, ['guards' => ['sanctum']]);
