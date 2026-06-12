<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $featureKeys = [
        'coupons',
        'dashboard_advanced',
        'intelligence',
        'whatsapp_auto',
        'whatsapp_bot',
        'whatsapp_ai',
        'ifood_integration',
        'advanced_reports',
        'delivery_areas',
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->corePlans() as $plan) {
            $existing = DB::table('plans')->where('slug', $plan['slug'])->first();

            if (!$existing) {
                DB::table('plans')->insert([
                    'name' => $plan['name'],
                    'slug' => $plan['slug'],
                    'description' => $plan['description'],
                    'price' => $plan['price'],
                    'max_products' => $plan['max_products'],
                    'features' => json_encode($plan['features']),
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }

            $existingFeatures = json_decode($existing->features ?: '{}', true) ?: [];
            $features = array_replace($plan['features'], $existingFeatures);

            DB::table('plans')
                ->where('id', $existing->id)
                ->update([
                    'features' => json_encode($this->normalizeFeatures($features)),
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        // Os planos podem estar em uso em produção. Não removemos dados no rollback.
    }

    private function corePlans(): array
    {
        return [
            [
                'name' => 'Trial',
                'slug' => 'trial',
                'description' => 'Plano gratuito de teste por 7 dias.',
                'price' => 0,
                'max_products' => 20,
                'features' => $this->features(),
            ],
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'Plano inicial para validar o cardápio digital.',
                'price' => 49.9,
                'max_products' => 30,
                'features' => $this->features(),
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'Plano para restaurantes em crescimento.',
                'price' => 89.9,
                'max_products' => 200,
                'features' => $this->features([
                    'coupons' => true,
                    'dashboard_advanced' => true,
                    'whatsapp_auto' => true,
                    'whatsapp_bot' => true,
                    'delivery_areas' => true,
                ]),
            ],
            [
                'name' => 'Premium',
                'slug' => 'premium',
                'description' => 'Plano avançado com integrações e automações premium.',
                'price' => 199,
                'max_products' => null,
                'features' => $this->features([
                    'coupons' => true,
                    'dashboard_advanced' => true,
                    'intelligence' => true,
                    'whatsapp_auto' => true,
                    'whatsapp_bot' => true,
                    'whatsapp_ai' => true,
                    'ifood_integration' => true,
                    'advanced_reports' => true,
                    'delivery_areas' => true,
                ]),
            ],
        ];
    }

    private function features(array $enabled = []): array
    {
        return $this->normalizeFeatures($enabled);
    }

    private function normalizeFeatures(array $features): array
    {
        return collect($this->featureKeys)
            ->mapWithKeys(fn (string $key) => [$key => (bool) ($features[$key] ?? false)])
            ->all();
    }
};
