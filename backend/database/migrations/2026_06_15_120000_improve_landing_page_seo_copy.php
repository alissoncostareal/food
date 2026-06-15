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

        if (! is_array($content) || ! isset($content['hero']) || ! is_array($content['hero'])) {
            return;
        }

        $defaults = LandingPageService::defaults()['hero'];
        $hero = $content['hero'];
        $changed = false;

        if (($hero['eyebrow'] ?? '') === 'Cardápio digital + delivery') {
            $hero['eyebrow'] = $defaults['eyebrow'];
            $changed = true;
        }

        if (($hero['title'] ?? '') === 'Seu delivery profissional') {
            $hero['title'] = $defaults['title'];
            $hero['highlight'] = $defaults['highlight'];
            $changed = true;
        }

        $subtitle = (string) ($hero['subtitle'] ?? '');

        if ($subtitle !== '' && ! str_contains(mb_strtolower($subtitle), 'partiumenu')) {
            $hero['subtitle'] = $defaults['subtitle'];
            $changed = true;
        }

        if (! $changed) {
            return;
        }

        $content['hero'] = $hero;

        DB::table('platform_settings')
            ->where('key', LandingPageService::SETTING_KEY)
            ->update([
                'value' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Hero copy is content; no automatic rollback.
    }
};
