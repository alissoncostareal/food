<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('option_groups', function (Blueprint $table) {
            $table->uuid('ifood_option_group_id')->nullable()->after('max_selected');
        });

        Schema::table('option_items', function (Blueprint $table) {
            $table->uuid('ifood_option_item_id')->nullable()->after('option_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('option_items', function (Blueprint $table) {
            $table->dropColumn('ifood_option_item_id');
        });

        Schema::table('option_groups', function (Blueprint $table) {
            $table->dropColumn('ifood_option_group_id');
        });
    }
};
