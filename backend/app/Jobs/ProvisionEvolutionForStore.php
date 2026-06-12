<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\WhatsappProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionEvolutionForStore implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $storeId
    ) {}

    public function handle(WhatsappProvisioningService $provisioning): void
    {
        $store = Store::query()->with('plan')->find($this->storeId);

        if (! $store || ! $store->canUseFeature('whatsapp_auto')) {
            return;
        }

        $provisioning->provision($store);
    }
}
