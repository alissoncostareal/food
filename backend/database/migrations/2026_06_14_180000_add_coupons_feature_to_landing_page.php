<?php

use App\Services\LandingPageService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('platform_settings')
            ->where('key', LandingPageService::SETTING_KEY)
            ->first();

        if (! $row || ! is_string($row->value) || trim($row->value) === '') {
            return;
        }

        $content = json_decode($row->value, true);

        if (! is_array($content) || ! isset($content['features']) || ! is_array($content['features'])) {
            return;
        }

        $hasCoupons = collect($content['features'])->contains(
            fn (array $feature) => ($feature['icon'] ?? '') === 'ticket'
                || str_contains(mb_strtolower((string) ($feature['title'] ?? '')), 'cupom')
        );

        if ($hasCoupons) {
            return;
        }

        $couponFeature = collect(LandingPageService::defaults()['features'])
            ->first(fn (array $feature) => ($feature['icon'] ?? '') === 'ticket');

        if (! is_array($couponFeature)) {
            return;
        }

        $features = $content['features'];
        $ifoodIndex = collect($features)->search(fn (array $feature) => ($feature['icon'] ?? '') === 'package');
        $insertAt = $ifoodIndex === false ? count($features) : $ifoodIndex + 1;

        array_splice($features, $insertAt, 0, [$couponFeature]);
        $content['features'] = $features;

        DB::table('platform_settings')
            ->where('key', LandingPageService::SETTING_KEY)
            ->update([
                'value' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $row = DB::table('platform_settings')
            ->where('key', LandingPageService::SETTING_KEY)
            ->first();

        if (! $row || ! is_string($row->value) || trim($row->value) === '') {
            return;
        }

        $content = json_decode($row->value, true);

        if (! is_array($content) || ! isset($content['features']) || ! is_array($content['features'])) {
            return;
        }

        $content['features'] = collect($content['features'])
            ->reject(fn (array $feature) => ($feature['icon'] ?? '') === 'ticket')
            ->values()
            ->all();

        DB::table('platform_settings')
            ->where('key', LandingPageService::SETTING_KEY)
            ->update([
                'value' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
};
