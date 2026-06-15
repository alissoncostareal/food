<?php

namespace App\Console\Commands;

use App\Models\Store;
use Illuminate\Console\Command;

class ProcessSubscriptionExpirations extends Command
{
    protected $signature = 'subscriptions:process-expirations';

    protected $description = 'Expira cortesias vencidas; lojas em trial voltam ao Trial, demais exigem assinatura paga';

    public function handle(): int
    {
        $count = 0;

        Store::query()
            ->where('subscription_status', 'complimentary')
            ->whereNotNull('complimentary_until')
            ->where('complimentary_until', '<', now())
            ->each(function (Store $store) use (&$count) {
                $store->ensureSubscriptionStateIsCurrent();
                $count++;
            });

        $this->info("Cortesias expiradas processadas: {$count}");

        return self::SUCCESS;
    }
}
