<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->uuid('ifood_category_id')->nullable()->after('position');
            $table->unique(['store_id', 'ifood_category_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->uuid('ifood_item_id')->nullable()->after('product_category_id');
            $table->unique(['store_id', 'ifood_item_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'ifood_item_id']);
            $table->dropColumn('ifood_item_id');
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'ifood_category_id']);
            $table->dropColumn('ifood_category_id');
        });
    }
};
