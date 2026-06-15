<?php

use App\Services\LandingPageService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('store_name')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });

        $now = now();
        $defaults = json_encode(LandingPageService::defaults(), JSON_UNESCAPED_UNICODE);

        DB::table('platform_settings')->updateOrInsert(
            ['key' => LandingPageService::SETTING_KEY],
            [
                'value' => $defaults,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_leads');

        DB::table('platform_settings')
            ->where('key', LandingPageService::SETTING_KEY)
            ->delete();
    }
};
