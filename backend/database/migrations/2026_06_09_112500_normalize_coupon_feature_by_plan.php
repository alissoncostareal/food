<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->setCouponFeature(['trial', 'starter', 'basic'], false);
        $this->setCouponFeature(['pro', 'premium'], true);
    }

    public function down(): void
    {
        $this->setCouponFeature(['starter', 'basic', 'pro', 'premium'], true);
        $this->setCouponFeature(['trial'], false);
    }

    private function setCouponFeature(array $slugs, bool $enabled): void
    {
        $plans = DB::table('plans')
            ->whereIn('slug', $slugs)
            ->get(['id', 'features']);

        foreach ($plans as $plan) {
            $features = json_decode($plan->features ?: '{}', true) ?: [];
            $features['coupons'] = $enabled;

            DB::table('plans')
                ->where('id', $plan->id)
                ->update([
                    'features' => json_encode($features),
                    'updated_at' => now(),
                ]);
        }
    }
};
