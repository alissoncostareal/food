<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stores', 'store_type')) {
            return;
        }

        // Lojas criadas antes da hierarquia (ex.: Mc Donalds) viram matriz.
        DB::table('stores')
            ->where(function ($query) {
                $query->whereNull('store_type')
                    ->orWhere('store_type', '');
            })
            ->update([
                'store_type' => 'matriz',
                'parent_store_id' => null,
            ]);

        DB::table('stores')
            ->whereNull('parent_store_id')
            ->where('store_type', '!=', 'filial')
            ->update([
                'store_type' => 'matriz',
            ]);

        // Garantia explícita para lojas legadas conhecidas.
        DB::table('stores')
            ->where(function ($query) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%mc donald%'])
                    ->orWhereRaw('LOWER(slug) LIKE ?', ['%mc-donald%'])
                    ->orWhereRaw('LOWER(slug) LIKE ?', ['%mcdonald%']);
            })
            ->update([
                'store_type' => 'matriz',
                'parent_store_id' => null,
            ]);

        if (! Schema::hasColumn('users', 'current_store_id')) {
            return;
        }

        // Donos com loja existente não devem cair no onboarding.
        $owners = DB::table('users')
            ->where('role', 'store_owner')
            ->whereNull('current_store_id')
            ->get(['id']);

        foreach ($owners as $owner) {
            $storeId = DB::table('stores')
                ->where('user_id', $owner->id)
                ->where(function ($query) {
                    $query->where('store_type', 'matriz')
                        ->orWhereNull('store_type');
                })
                ->orderBy('id')
                ->value('id');

            if (! $storeId) {
                $storeId = DB::table('stores')
                    ->where('user_id', $owner->id)
                    ->orderBy('id')
                    ->value('id');
            }

            if ($storeId) {
                DB::table('users')
                    ->where('id', $owner->id)
                    ->update(['current_store_id' => $storeId]);
            }
        }
    }

    public function down(): void
    {
        // Dados de compatibilidade — não revertemos.
    }
};
