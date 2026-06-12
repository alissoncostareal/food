<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('online_payments_enabled')->default(false)->after('accepted_payment_methods');
            $table->string('pagarme_recipient_id')->nullable()->after('pagarme_last_charge_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_status', 32)->default('not_required')->after('payment_method');
            $table->string('payment_channel', 16)->default('offline')->after('payment_status');
            $table->string('pagarme_order_id')->nullable()->after('payment_channel');
            $table->string('pagarme_charge_id')->nullable()->after('pagarme_order_id');
            $table->text('pix_qr_code')->nullable()->after('pagarme_charge_id');
            $table->string('pix_qr_code_url', 2048)->nullable()->after('pix_qr_code');
            $table->timestamp('payment_expires_at')->nullable()->after('pix_qr_code_url');
            $table->timestamp('payment_paid_at')->nullable()->after('payment_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_status',
                'payment_channel',
                'pagarme_order_id',
                'pagarme_charge_id',
                'pix_qr_code',
                'pix_qr_code_url',
                'payment_expires_at',
                'payment_paid_at',
            ]);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['online_payments_enabled', 'pagarme_recipient_id']);
        });
    }
};
