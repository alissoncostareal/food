<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_category_id')->constrained('product_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'product_category_id']);
        });

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'product_category_id')) {
            $rows = DB::table('products')
                ->whereNotNull('product_category_id')
                ->select('id as product_id', 'product_category_id')
                ->get();

            $now = now();

            foreach ($rows as $row) {
                DB::table('category_product')->insertOrIgnore([
                    'product_id' => $row->product_id,
                    'product_category_id' => $row->product_category_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
    }
};
