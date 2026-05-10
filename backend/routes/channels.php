<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $storeId) {
    // Só permite escutar se o usuário for dono da loja que está sendo chamada
    return (int) $user->store->id === (int) $storeId;
});
