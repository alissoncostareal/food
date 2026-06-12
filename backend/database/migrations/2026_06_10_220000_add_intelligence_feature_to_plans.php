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
        $plans = DB::table('plans')->get();

        foreach ($plans as $plan) {
            $features = json_decode($plan->features ?: '{}', true) ?: [];

            if (!array_key_exists('intelligence', $features)) {
                $features['intelligence'] = (bool) ($features['dashboard_advanced'] ?? false);
            }

            if (in_array($plan->slug, ['premium'], true)) {
                $features['intelligence'] = true;
            }

            DB::table('plans')
                ->where('id', $plan->id)
                ->update([
                    'features' => json_encode($this->normalizeFeatures($features)),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        $plans = DB::table('plans')->get();

        foreach ($plans as $plan) {
            $features = json_decode($plan->features ?: '{}', true) ?: [];
            unset($features['intelligence']);

            DB::table('plans')
                ->where('id', $plan->id)
                ->update([
                    'features' => json_encode($features),
                    'updated_at' => now(),
                ]);
        }
    }

    private function normalizeFeatures(array $features): array
    {
        return collect($this->featureKeys)
            ->mapWithKeys(fn (string $key) => [$key => (bool) ($features[$key] ?? false)])
            ->all();
    }
};
