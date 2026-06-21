<?php

use App\Support\ModuleMaintenance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }

        $payload = json_encode(ModuleMaintenance::blankConfig(), JSON_UNESCAPED_UNICODE);

        DB::table('platform_settings')->updateOrInsert(
            ['key' => ModuleMaintenance::SETTING_KEY],
            [
                'value' => $payload,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('platform_settings')
            ->where('key', ModuleMaintenance::SETTING_KEY)
            ->delete();
    }
};
