<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'description')) {
                $table->string('description')->nullable()->after('slug');
            }

            if (!Schema::hasColumn('plans', 'price')) {
                $table->decimal('price', 10, 2)->default(0)->after('description');
            }

            if (!Schema::hasColumn('plans', 'max_products')) {
                $table->integer('max_products')->nullable()->after('price');
            }

            if (!Schema::hasColumn('plans', 'features')) {
                $table->json('features')->nullable()->after('max_products');
            }

            if (!Schema::hasColumn('plans', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('features');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (Schema::hasColumn('plans', 'is_active')) {
                $table->dropColumn('is_active');
            }

            if (Schema::hasColumn('plans', 'features')) {
                $table->dropColumn('features');
            }

            if (Schema::hasColumn('plans', 'max_products')) {
                $table->dropColumn('max_products');
            }

            if (Schema::hasColumn('plans', 'price')) {
                $table->dropColumn('price');
            }

            if (Schema::hasColumn('plans', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
