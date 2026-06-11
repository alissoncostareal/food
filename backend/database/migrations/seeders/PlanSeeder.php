<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'price' => 0.00,
                'description' => 'Plano inicial para validar o cardápio digital.',
                'max_products' => 30,
                'features' => [
                    'coupons' => false,
                    'dashboard_advanced' => false,
                    'intelligence' => false,
                    'whatsapp_auto' => false,
                    'whatsapp_bot' => false,
                    'whatsapp_ai' => false,
                    'ifood_integration' => false,
                    'advanced_reports' => false,
                    'delivery_areas' => false,
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'price' => 89.90,
                'description' => 'Plano para restaurantes em crescimento.',
                'max_products' => 200,
                'features' => [
                    'coupons' => true,
                    'dashboard_advanced' => true,
                    'intelligence' => false,
                    'whatsapp_auto' => true,
                    'whatsapp_bot' => true,
                    'whatsapp_ai' => false,
                    'ifood_integration' => false,
                    'advanced_reports' => false,
                    'delivery_areas' => true,
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Premium',
                'slug' => 'premium',
                'price' => 199.90,
                'description' => 'Plano completo com produtos ilimitados e automações avançadas.',
                'max_products' => null,
                'features' => [
                    'coupons' => true,
                    'dashboard_advanced' => true,
                    'intelligence' => true,
                    'whatsapp_auto' => true,
                    'whatsapp_bot' => true,
                    'whatsapp_ai' => true,
                    'ifood_integration' => true,
                    'advanced_reports' => true,
                    'delivery_areas' => true,
                ],
                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan
            );
        }
    }
}
