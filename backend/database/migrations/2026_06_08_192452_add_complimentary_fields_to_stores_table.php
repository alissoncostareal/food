<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (!Schema::hasColumn('stores', 'complimentary_until')) {
                $table->timestamp('complimentary_until')->nullable()->after('subscription_ends_at');
            }

            if (!Schema::hasColumn('stores', 'complimentary_reason')) {
                $table->string('complimentary_reason')->nullable()->after('complimentary_until');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'complimentary_reason')) {
                $table->dropColumn('complimentary_reason');
            }

            if (Schema::hasColumn('stores', 'complimentary_until')) {
                $table->dropColumn('complimentary_until');
            }
        });
    }
};
