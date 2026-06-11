<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('evolution_instance_name')->nullable()->after('whatsapp_number');
            $table->string('evolution_status', 32)->nullable()->after('evolution_instance_name');
            $table->timestamp('evolution_connected_at')->nullable()->after('evolution_status');
            $table->text('evolution_last_error')->nullable()->after('evolution_connected_at');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'evolution_instance_name',
                'evolution_status',
                'evolution_connected_at',
                'evolution_last_error',
            ]);
        });
    }
};
