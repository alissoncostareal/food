<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_areas', function (Blueprint $table) {
            $table->string('city', 120)->nullable()->after('district_name');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_areas', function (Blueprint $table) {
            $table->dropColumn('city');
        });
    }
};
