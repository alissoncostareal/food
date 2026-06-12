<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_payment_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('connection_method', 32)->default('api_keys');
            $table->text('credentials')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('account_label')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('is_active_for_pix')->default(false);
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'provider']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('payment_pix_provider_id')
                ->nullable()
                ->after('pagarme_recipient_id')
                ->constrained('store_payment_providers')
                ->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_provider', 32)->nullable()->after('payment_channel');
            $table->string('payment_external_order_id')->nullable()->after('pagarme_order_id');
            $table->string('payment_external_charge_id')->nullable()->after('pagarme_charge_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_provider',
                'payment_external_order_id',
                'payment_external_charge_id',
            ]);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_pix_provider_id');
        });

        Schema::dropIfExists('store_payment_providers');
    }
};
