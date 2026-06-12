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
            $table->dropUnique(['store_id', 'display_number']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('display_number', 32)->change();
        });

        DB::table('orders')
            ->where('order_source', 'ifood')
            ->whereNotNull('ifood_display_id')
            ->where('ifood_display_id', '!=', '')
            ->orderBy('id')
            ->each(function (object $order) {
                DB::table('orders')
                    ->where('id', $order->id)
                    ->update(['display_number' => substr(trim((string) $order->ifood_display_id), 0, 32)]);
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('display_number', 4)->change();
            $table->unique(['store_id', 'display_number']);
        });
    }
};
