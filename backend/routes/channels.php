<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('store.{storeId}', function ($user, $storeId) {
    return true; // SE ISSO FUNCIONAR, O PROBLEMA É A COMPARAÇÃO DOS IDS ABAIXO
}, ['guards' => ['sanctum']]);
