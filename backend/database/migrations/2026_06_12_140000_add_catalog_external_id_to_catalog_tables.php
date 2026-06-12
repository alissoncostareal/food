<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->string('catalog_external_id', 120)->nullable()->after('ifood_category_id');
            $table->unique(['store_id', 'catalog_external_id'], 'product_categories_store_catalog_external_unique');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('catalog_external_id', 120)->nullable()->after('ifood_item_id');
            $table->unique(['store_id', 'catalog_external_id'], 'products_store_catalog_external_unique');
        });

        Schema::table('option_groups', function (Blueprint $table) {
            $table->string('catalog_external_id', 120)->nullable()->after('ifood_option_group_id');
        });

        Schema::table('option_items', function (Blueprint $table) {
            $table->string('catalog_external_id', 120)->nullable()->after('ifood_option_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropUnique('product_categories_store_catalog_external_unique');
            $table->dropColumn('catalog_external_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_store_catalog_external_unique');
            $table->dropColumn('catalog_external_id');
        });

        Schema::table('option_groups', function (Blueprint $table) {
            $table->dropColumn('catalog_external_id');
        });

        Schema::table('option_items', function (Blueprint $table) {
            $table->dropColumn('catalog_external_id');
        });
    }
};
