<?php

use App\Models\Store;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Store::query()
            ->whereNull('business_hours')
            ->orderBy('id')
            ->each(function (Store $store) {
                $store->business_hours = Store::defaultBusinessHours();

                if (! $store->is_open) {
                    $store->is_open = true;
                }

                $store->saveQuietly();
                $store->syncOperatingHoursFromBusinessHours();
            });
    }

    public function down(): void
    {
        // Dados de horário não são revertidos automaticamente.
    }
};
