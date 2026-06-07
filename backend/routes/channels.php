<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('store.{storeId}', function ($user, $storeId) {
    // 1. Loga exatamente o que está acontecendo no servidor
    Log::info('Autorização de Canal - Store:', [
        'user_id' => $user->id,
        'user_store_id' => $user->store_id, // Verifique se esta é a coluna correta
        'requested_store_id' => $storeId,
        'tipo_user_store_id' => gettype($user->store_id),
        'tipo_store_id' => gettype($storeId)
    ]);

    // 2. Tenta encontrar a loja do usuário de forma segura
    // Se o seu usuário tem a coluna 'store_id' na tabela 'users', use-a.
    // Se você precisa acessar via relação, use $user->store->id

    $userStoreId = $user->store_id ?? ($user->store ? $user->store->id : null);

    return (int) $userStoreId === (int) $storeId;
}, ['guards' => ['sanctum']]);
