<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'pre_courtesy_plan_id')) {
                $table->foreignId('pre_courtesy_plan_id')->nullable()->after('complimentary_reason')->constrained('plans')->nullOnDelete();
            }

            if (! Schema::hasColumn('stores', 'pre_courtesy_subscription_status')) {
                $table->string('pre_courtesy_subscription_status')->nullable()->after('pre_courtesy_plan_id');
            }

            if (! Schema::hasColumn('stores', 'pre_courtesy_subscription_ends_at')) {
                $table->timestamp('pre_courtesy_subscription_ends_at')->nullable()->after('pre_courtesy_subscription_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'pre_courtesy_plan_id')) {
                $table->dropConstrainedForeignId('pre_courtesy_plan_id');
            }

            if (Schema::hasColumn('stores', 'pre_courtesy_subscription_status')) {
                $table->dropColumn('pre_courtesy_subscription_status');
            }

            if (Schema::hasColumn('stores', 'pre_courtesy_subscription_ends_at')) {
                $table->dropColumn('pre_courtesy_subscription_ends_at');
            }
        });
    }
};
