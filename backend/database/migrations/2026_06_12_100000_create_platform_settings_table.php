<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\ModuleMaintenance;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        $now = now();
        $moduleMaintenance = json_encode(ModuleMaintenance::blankConfig(), JSON_UNESCAPED_UNICODE);

        DB::table('platform_settings')->insert([
            [
                'key' => 'payment_grace_days',
                'value' => '7',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'extra_branch_monthly_price',
                'value' => '49.90',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => ModuleMaintenance::SETTING_KEY,
                'value' => $moduleMaintenance,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
