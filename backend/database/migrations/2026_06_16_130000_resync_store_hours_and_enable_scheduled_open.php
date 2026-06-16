<?php

use App\Models\Store;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Store::query()
            ->orderBy('id')
            ->each(function (Store $store) {
                $changed = false;

                if (blank($store->business_hours)) {
                    $store->business_hours = Store::defaultBusinessHours();
                    $changed = true;
                }

                if (! $store->is_open && $this->hasOpenDays($store)) {
                    $store->is_open = true;
                    $changed = true;
                }

                if ($changed) {
                    $store->saveQuietly();
                }

                $store->syncOperatingHoursFromBusinessHours();
            });
    }

    public function down(): void
    {
        // Dados de horário não são revertidos automaticamente.
    }

    private function hasOpenDays(Store $store): bool
    {
        $hours = $store->business_hours;

        if (! is_array($hours) || $hours === []) {
            return false;
        }

        foreach ($hours as $day) {
            if (! is_array($day) || ! empty($day['closed'])) {
                continue;
            }

            if (! empty($day['all_day'])) {
                return true;
            }

            if (filled($day['open'] ?? null) && filled($day['close'] ?? null)) {
                return true;
            }
        }

        return false;
    }
};
