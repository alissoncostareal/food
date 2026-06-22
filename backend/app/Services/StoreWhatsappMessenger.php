<?php

namespace App\Services;

use App\Models\Store;

class StoreWhatsappMessenger
{
    public function __construct(
        private readonly EvolutionService $evolution,
        private readonly MetaWhatsappService $meta,
        private readonly StoreWhatsappConnectionService $connection,
    ) {}

    public function sendText(Store $store, string $phone, string $text): void
    {
        if ($store->usesMetaWhatsapp()) {
            $this->meta->sendText($store, $phone, $text);

            return;
        }

        $this->evolution->sendTextForStore($store, $phone, $text);
    }

    public function canSend(Store $store): bool
    {
        if (! $this->connection->isConnected($store)) {
            return false;
        }

        if ($store->usesMetaWhatsapp()) {
            return $this->meta->isConfigured();
        }

        return $this->evolution->isConfigured();
    }
}
