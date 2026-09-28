<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasColumn('plans', 'max_products')) {
            return;
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->integer('max_products')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasColumn('plans', 'max_products')) {
            return;
        }

        DB::table('plans')->whereNull('max_products')->update(['max_products' => 0]);

        Schema::table('plans', function (Blueprint $table) {
            $table->integer('max_products')->nullable(false)->default(0)->change();
        });
    }
};
