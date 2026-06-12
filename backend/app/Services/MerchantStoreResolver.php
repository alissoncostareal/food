<?php

namespace App\Services;

use App\Models\Store;
use App\Models\User;

class MerchantStoreResolver
{
    public function resolve(User $user): ?Store
    {
        if (!$user->isMerchantUser()) {
            return null;
        }

        $user->loadMissing(['store', 'storeMemberships']);

        if ($user->isStoreOwner()) {
            $ownedStore = $user->store;

            if (! $ownedStore) {
                $ownedStore = Store::query()
                    ->where('user_id', $user->id)
                    ->orderBy('id')
                    ->first();
            }

            if ($ownedStore) {
                if ($user->current_store_id && $user->canAccessStore((int) $user->current_store_id)) {
                    return Store::query()->find($user->current_store_id);
                }

                return $ownedStore;
            }
        }

        if ($user->current_store_id && $user->canAccessStore((int) $user->current_store_id)) {
            return Store::query()->with('plan')->find($user->current_store_id);
        }

        $membershipStoreId = $user->storeMemberships()
            ->orderBy('id')
            ->value('store_id');

        if (!$membershipStoreId) {
            return null;
        }

        return Store::query()->with('plan')->find($membershipStoreId);
    }

    public function resolveOrFail(User $user): Store
    {
        $store = $this->resolve($user);

        if (!$store) {
            abort(403, 'Loja não encontrada para este usuário.');
        }

        return $store;
    }
}
