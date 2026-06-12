<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\WhatsappInboundHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWhatsappInboundMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $storeId,
        public array $payload,
        public string $event = '',
    ) {}

    public function handle(WhatsappInboundHandler $handler): void
    {
        $store = Store::with('plan')->find($this->storeId);

        if (! $store) {
            return;
        }

        $handler->handle($store, $this->payload, $this->event);
    }
}
