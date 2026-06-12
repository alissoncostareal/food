<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('store_type', 20)->default('matriz')->after('user_id');
            $table->foreignId('parent_store_id')
                ->nullable()
                ->after('store_type')
                ->constrained('stores')
                ->nullOnDelete();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('max_stores')->default(1)->after('max_products');
        });

        DB::table('stores')->update(['store_type' => 'matriz']);

        DB::table('plans')->update(['max_stores' => 1]);
        DB::table('plans')->where('slug', 'premium')->update(['max_stores' => 3]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('max_stores');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_store_id');
            $table->dropColumn('store_type');
        });
    }
};
