<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('billing_email')->nullable()->after('subscription_ends_at');
            $table->string('mercado_pago_preapproval_id')->nullable()->after('billing_email');
            $table->string('mercado_pago_subscription_status')->nullable()->after('mercado_pago_preapproval_id');
            $table->string('mercado_pago_last_payment_id')->nullable()->after('mercado_pago_subscription_status');
            $table->timestamp('mercado_pago_last_payment_at')->nullable()->after('mercado_pago_last_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'billing_email',
                'mercado_pago_preapproval_id',
                'mercado_pago_subscription_status',
                'mercado_pago_last_payment_id',
                'mercado_pago_last_payment_at',
            ]);
        });
    }
};
