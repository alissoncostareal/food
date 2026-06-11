<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\IfoodOrderHandler;
use App\Services\IfoodService;
use Illuminate\Console\Command;

class PollIfoodEvents extends Command
{
    protected $signature = 'ifood:poll-events';

    protected $description = 'Consulta eventos iFood pendentes e confirma recebimento (backup do webhook)';

    public function handle(IfoodService $ifood, IfoodOrderHandler $handler): int
    {
        $stores = Store::query()
            ->where('ifood_integration_status', 'connected')
            ->whereNotNull('ifood_merchant_id')
            ->get();

        $processed = 0;

        foreach ($stores as $store) {
            try {
                $events = $ifood->pollEvents($store);
                $eventIds = [];

                foreach ($events as $event) {
                    if (! is_array($event)) {
                        continue;
                    }

                    $eventIds[] = (string) data_get($event, 'id');
                    $handler->handle($store, $event);
                    $processed++;
                }

                $ifood->acknowledgeEvents($store, array_filter($eventIds));
            } catch (\Throwable $e) {
                $this->warn("Loja {$store->id}: {$e->getMessage()}");
            }
        }

        $this->info("Eventos iFood processados: {$processed}");

        return self::SUCCESS;
    }
}
