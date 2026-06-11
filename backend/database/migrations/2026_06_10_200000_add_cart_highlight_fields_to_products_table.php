<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'show_in_cart')) {
                $table->boolean('show_in_cart')->default(false)->after('is_active');
            }

            if (!Schema::hasColumn('products', 'cart_highlight_order')) {
                $table->unsignedSmallInteger('cart_highlight_order')->nullable()->after('show_in_cart');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'cart_highlight_order')) {
                $table->dropColumn('cart_highlight_order');
            }

            if (Schema::hasColumn('products', 'show_in_cart')) {
                $table->dropColumn('show_in_cart');
            }
        });
    }
};
