<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $features = [
            'coupons' => false,
            'dashboard_advanced' => false,
            'whatsapp_auto' => false,
            'whatsapp_bot' => false,
            'whatsapp_ai' => false,
            'ifood_integration' => false,
            'advanced_reports' => false,
            'delivery_areas' => false,
        ];

        $existingTrial = DB::table('plans')->where('slug', 'trial')->first();

        if ($existingTrial) {
            DB::table('plans')
                ->where('id', $existingTrial->id)
                ->update([
                    'name' => 'Trial',
                    'description' => 'Plano gratuito de teste por 7 dias.',
                    'price' => 0,
                    'max_products' => 20,
                    'features' => json_encode($features),
                    'is_active' => true,
                    'updated_at' => $now,
                ]);

            return;
        }

        DB::table('plans')->insert([
            'name' => 'Trial',
            'slug' => 'trial',
            'description' => 'Plano gratuito de teste por 7 dias.',
            'price' => 0,
            'max_products' => 20,
            'features' => json_encode($features),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('plans')->where('slug', 'trial')->delete();
    }
};
