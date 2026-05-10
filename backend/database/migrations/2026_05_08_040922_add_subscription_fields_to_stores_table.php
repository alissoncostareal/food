<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('subscription_status')->default('trial'); // trial, active, past_due, suspended
            $table->timestamp('subscription_ends_at')->nullable();
            // Se quiser cobrar por níveis de plano (ex: limites de produtos)
            $table->string('plan_type')->default('basic');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            //
        });
    }
};
