<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('ifood_merchant_id')->nullable()->after('pagarme_last_charge_at');
            $table->text('ifood_access_token')->nullable();
            $table->text('ifood_refresh_token')->nullable();
            $table->timestamp('ifood_token_expires_at')->nullable();
            $table->string('ifood_integration_status')->default('disconnected');
            $table->text('ifood_last_error')->nullable();
            $table->timestamp('ifood_connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'ifood_merchant_id',
                'ifood_access_token',
                'ifood_refresh_token',
                'ifood_token_expires_at',
                'ifood_integration_status',
                'ifood_last_error',
                'ifood_connected_at',
            ]);
        });
    }
};
