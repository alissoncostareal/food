<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('store.{storeId}', function ($user, $storeId) {
    // Adicione este log para ver o que o Laravel está recebendo
    Log::info('Tentativa de acesso ao canal:', [
        'user_id' => $user->id,
        'user_store_id' => $user->store_id, // Ou use $user->store?->id
        'requested_store_id' => $storeId
    ]);

    // Forçar conversão para string ou int para garantir que não haja erro de tipo
    return (string) $user->store_id === (string) $storeId;
}, ['guards' => ['sanctum']]);
