<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('launch_price', 10, 2)->nullable()->after('price');
            $table->unsignedInteger('launch_slots')->nullable()->after('launch_price');
            $table->unsignedSmallInteger('launch_price_months')->default(12)->after('launch_slots');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->decimal('subscription_locked_price', 10, 2)->nullable()->after('pagarme_last_charge_at');
            $table->timestamp('subscription_price_until')->nullable()->after('subscription_locked_price');
            $table->timestamp('manual_closed_until')->nullable()->after('open_outside_hours');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['launch_price', 'launch_slots', 'launch_price_months']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['subscription_locked_price', 'subscription_price_until', 'manual_closed_until']);
        });
    }
};
