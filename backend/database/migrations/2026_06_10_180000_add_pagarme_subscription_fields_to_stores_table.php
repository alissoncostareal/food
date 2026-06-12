<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $legacyMercadoPagoColumns = [
                'mercado_pago_preapproval_id',
                'mercado_pago_subscription_status',
                'mercado_pago_last_payment_id',
                'mercado_pago_last_payment_at',
            ];

            foreach ($legacyMercadoPagoColumns as $column) {
                if (Schema::hasColumn('stores', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('stores', function (Blueprint $table) {
            if (!Schema::hasColumn('stores', 'billing_email')) {
                $table->string('billing_email')->nullable()->after('subscription_ends_at');
            }

            if (!Schema::hasColumn('stores', 'pagarme_customer_id')) {
                $table->string('pagarme_customer_id')->nullable()->after('billing_email');
            }

            if (!Schema::hasColumn('stores', 'pagarme_subscription_id')) {
                $table->string('pagarme_subscription_id')->nullable()->after('pagarme_customer_id');
            }

            if (!Schema::hasColumn('stores', 'pagarme_subscription_status')) {
                $table->string('pagarme_subscription_status')->nullable()->after('pagarme_subscription_id');
            }

            if (!Schema::hasColumn('stores', 'pagarme_last_charge_id')) {
                $table->string('pagarme_last_charge_id')->nullable()->after('pagarme_subscription_status');
            }

            if (!Schema::hasColumn('stores', 'pagarme_last_charge_at')) {
                $table->timestamp('pagarme_last_charge_at')->nullable()->after('pagarme_last_charge_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $columns = [
                'billing_email',
                'pagarme_customer_id',
                'pagarme_subscription_id',
                'pagarme_subscription_status',
                'pagarme_last_charge_id',
                'pagarme_last_charge_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('stores', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
