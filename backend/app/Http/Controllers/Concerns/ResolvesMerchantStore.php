<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Store;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

trait ResolvesMerchantStore
{
    protected function merchantStore(): Store
    {
        $store = request()->attributes->get('merchant_store');

        if ($store instanceof Store) {
            return $store;
        }

        $store = Auth::user()?->resolveMerchantStore();

        if (! $store) {
            throw new HttpResponseException(response()->json([
                'error' => 'Loja não configurada.',
            ], 404));
        }

        return $store;
    }
}
