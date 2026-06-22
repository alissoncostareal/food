<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('whatsapp_provider', 16)->default('evolution')->after('whatsapp_number');
            $table->string('meta_waba_id')->nullable()->after('evolution_last_error');
            $table->string('meta_phone_number_id')->nullable()->after('meta_waba_id');
            $table->text('meta_access_token')->nullable()->after('meta_phone_number_id');
            $table->string('meta_whatsapp_status', 32)->nullable()->after('meta_access_token');
            $table->timestamp('meta_connected_at')->nullable()->after('meta_whatsapp_status');
            $table->text('meta_last_error')->nullable()->after('meta_connected_at');
            $table->string('meta_display_phone', 32)->nullable()->after('meta_last_error');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_provider',
                'meta_waba_id',
                'meta_phone_number_id',
                'meta_access_token',
                'meta_whatsapp_status',
                'meta_connected_at',
                'meta_last_error',
                'meta_display_phone',
            ]);
        });
    }
};
