<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('store.{storeId}', function ($user, $storeId) {
    // Log detalhado para encontrar o culpado
    Log::info('DEBUG BROADCAST', [
        'user_id' => $user->id,
        'user_store_id' => $user->store_id, // Verifique se esse campo existe no seu banco
        'requested_id' => $storeId,
        'match' => (int)$user->store_id === (int)$storeId
    ]);

    // Força a conversão para inteiro para evitar erro de tipo
    return (int) $user->store_id === (int) $storeId;
}, ['guards' => ['sanctum']]);
