<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_source', 20)->default('web')->after('store_id');
            $table->uuid('ifood_order_id')->nullable()->after('order_source');
            $table->string('ifood_display_id', 32)->nullable()->after('ifood_order_id');

            $table->unique('ifood_order_id');
            $table->index(['store_id', 'order_source']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['ifood_order_id']);
            $table->dropIndex(['store_id', 'order_source']);
            $table->dropColumn(['order_source', 'ifood_order_id', 'ifood_display_id']);
        });
    }
};
