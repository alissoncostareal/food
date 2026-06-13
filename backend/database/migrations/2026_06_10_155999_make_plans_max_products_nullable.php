<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasColumn('plans', 'max_products')) {
            return;
        }

        DB::statement('ALTER TABLE plans ALTER COLUMN max_products DROP NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasColumn('plans', 'max_products')) {
            return;
        }

        DB::table('plans')->whereNull('max_products')->update(['max_products' => 0]);
        DB::statement('ALTER TABLE plans ALTER COLUMN max_products SET NOT NULL');
    }
};
