<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('store.{storeId}', function ($user, $storeId) {
    if (! $user instanceof User) {
        return false;
    }

    return $user->canAccessStore((int) $storeId);
});
