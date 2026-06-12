<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('whatsapp_ai_enabled')->default(false)->change();
        });

        $minChars = 20;

        DB::table('stores')
            ->select(['id', 'whatsapp_ai_faq'])
            ->orderBy('id')
            ->chunkById(100, function ($stores) use ($minChars) {
                foreach ($stores as $store) {
                    if (mb_strlen(trim((string) $store->whatsapp_ai_faq)) < $minChars) {
                        DB::table('stores')
                            ->where('id', $store->id)
                            ->update(['whatsapp_ai_enabled' => false]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('whatsapp_ai_enabled')->default(true)->change();
        });
    }
};
