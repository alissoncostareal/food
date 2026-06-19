<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderPixPaymentService;
use Illuminate\Console\Command;

class ExpireUnpaidPixOrders extends Command
{
    protected $signature = 'orders:expire-unpaid-pix';

    protected $description = 'Cancela pedidos com Pix online não pago após o vencimento';

    public function handle(OrderPixPaymentService $payments): int
    {
        $ttlMinutes = max(5, (int) config('payments.unpaid_order_ttl_minutes', 30));
        $expiredCount = 0;

        Order::query()
            ->where('payment_status', OrderPixPaymentService::STATUS_AWAITING)
            ->where(function ($query) use ($ttlMinutes) {
                $query->where(function ($inner) {
                    $inner->whereNotNull('payment_expires_at')
                        ->where('payment_expires_at', '<=', now());
                })->orWhere(function ($inner) use ($ttlMinutes) {
                    $inner->whereNull('payment_expires_at')
                        ->where('created_at', '<=', now()->subMinutes($ttlMinutes));
                });
            })
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($payments, &$expiredCount) {
                foreach ($orders as $order) {
                    if ($payments->markExpired($order)) {
                        $expiredCount++;
                    }
                }
            });

        if ($expiredCount > 0) {
            $this->info("Pedidos Pix expirados e cancelados: {$expiredCount}");
        }

        return self::SUCCESS;
    }
}
