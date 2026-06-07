<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('store.{storeId}', function ($user, $storeId) {
    // 1. Log para debugar
    Log::info('Tentativa de Autorização de Canal', [
        'user_exists' => !is_null($user),
        'user_id' => $user ? $user->id : 'nulo',
        'store_id_db' => $user ? ($user->store_id ?? 'sem store_id') : 'nulo',
        'requested_store_id' => $storeId
    ]);

    // 2. Se o usuário for nulo, o Sanctum não autenticou no canal
    if (!$user) return false;

    // 3. Comparação de IDs
    return (int) $user->store_id === (int) $storeId;
}, ['guards' => ['sanctum']]);
