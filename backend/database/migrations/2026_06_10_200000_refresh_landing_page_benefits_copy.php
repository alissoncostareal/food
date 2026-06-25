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

        if (! is_array($content)) {
            return;
        }

        $defaults = LandingPageService::defaults();
        $changed = false;

        if (isset($content['hero']) && is_array($content['hero'])) {
            $content['hero']['subtitle'] = $defaults['hero']['subtitle'];
            $changed = true;
        }

        if (isset($content['features_section']) && is_array($content['features_section'])) {
            $content['features_section']['title'] = $defaults['features_section']['title'];
            $content['features_section']['subtitle'] = $defaults['features_section']['subtitle'];
            $changed = true;
        }

        if (! isset($content['features']) || ! is_array($content['features'])) {
            if ($changed) {
                $this->persist($content);
            }

            return;
        }

        $features = $content['features'];
        $pedidosIndex = collect($features)->search(fn (array $feature) => ($feature['icon'] ?? '') === 'shopping-bag');
        $insertAt = $pedidosIndex === false ? min(2, count($features)) : $pedidosIndex + 1;
        $featuresToInsert = [];

        foreach (['zap', 'bookmark'] as $icon) {
            $exists = collect($features)->contains(fn (array $feature) => ($feature['icon'] ?? '') === $icon);

            if ($exists) {
                continue;
            }

            $newFeature = collect($defaults['features'])->first(fn (array $feature) => ($feature['icon'] ?? '') === $icon);

            if (is_array($newFeature)) {
                $featuresToInsert[] = $newFeature;
            }
        }

        if ($featuresToInsert !== []) {
            array_splice($features, $insertAt, 0, $featuresToInsert);
            $changed = true;
        }

        $whatsappIndex = collect($features)->search(fn (array $feature) => ($feature['icon'] ?? '') === 'message-circle');

        if ($whatsappIndex !== false) {
            $whatsappFeature = collect($defaults['features'])->first(fn (array $feature) => ($feature['icon'] ?? '') === 'message-circle');

            if (is_array($whatsappFeature)) {
                $features[$whatsappIndex] = array_replace($features[$whatsappIndex], $whatsappFeature);
                $changed = true;
            }
        }

        if ($changed) {
            $content['features'] = $features;
            $this->persist($content);
        }
    }

    public function down(): void
    {
        // Marketing copy; no automatic rollback.
    }

    private function persist(array $content): void
    {
        DB::table('platform_settings')
            ->where('key', LandingPageService::SETTING_KEY)
            ->update([
                'value' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
};
