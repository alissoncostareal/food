<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;

class ExpireStalePendingOrders extends Command
{
    protected $signature = 'orders:expire-stale-pending';

    protected $description = 'Cancela pedidos pendentes abandonados (fora da janela de aceite)';

    public function handle(): int
    {
        $hours = (int) config('orders.actionable_pending_hours', 24);

        $expired = Order::query()
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subHours($hours))
            ->update(['status' => 'canceled']);

        if ($expired > 0) {
            $this->info("Pedidos pendentes expirados: {$expired}");
        }

        return self::SUCCESS;
    }
}
