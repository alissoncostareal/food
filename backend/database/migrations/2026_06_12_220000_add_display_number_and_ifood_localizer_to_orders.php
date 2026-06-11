<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('display_number', 4)->nullable()->after('store_id');
            $table->string('ifood_delivery_localizer', 32)->nullable()->after('ifood_delivered_by');
        });

        $storeIds = DB::table('orders')->distinct()->pluck('store_id');

        foreach ($storeIds as $storeId) {
            $used = [];

            DB::table('orders')
                ->where('store_id', $storeId)
                ->orderBy('id')
                ->pluck('id')
                ->each(function (int $orderId) use (&$used, $storeId) {
                    $service = app(\App\Services\OrderDisplayNumberService::class);
                    $number = $service->assignUniqueForStore((int) $storeId, $used);
                    $used[] = $number;

                    DB::table('orders')
                        ->where('id', $orderId)
                        ->update(['display_number' => $number]);
                });
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('display_number', 4)->nullable(false)->change();
            $table->unique(['store_id', 'display_number']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'display_number']);
            $table->dropColumn(['display_number', 'ifood_delivery_localizer']);
        });
    }
};
