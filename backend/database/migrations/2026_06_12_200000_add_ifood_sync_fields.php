<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'ifood_auto_confirm')) {
                $table->boolean('ifood_auto_confirm')->default(false)->after('ifood_connected_at');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'ifood_confirmed_at')) {
                $table->timestamp('ifood_confirmed_at')->nullable()->after('ifood_display_id');
            }

            if (! Schema::hasColumn('orders', 'ifood_order_type')) {
                $table->string('ifood_order_type', 32)->nullable()->after('ifood_confirmed_at');
            }

            if (! Schema::hasColumn('orders', 'ifood_delivered_by')) {
                $table->string('ifood_delivered_by', 32)->nullable()->after('ifood_order_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'ifood_confirmed_at',
                'ifood_order_type',
                'ifood_delivered_by',
            ]);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('ifood_auto_confirm');
        });
    }
};
