<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderStockService
{
    public function restoreIfNeeded(Order $order): void
    {
        if ($order->stock_restored_at) {
            return;
        }

        DB::transaction(function () use ($order) {
            $locked = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->stock_restored_at) {
                return;
            }

            $locked->loadMissing('items.product');

            foreach ($locked->items as $item) {
                $product = $item->product;

                if ($product && $product->manage_stock) {
                    $product->increment('stock_quantity', (int) $item->quantity);
                }
            }

            $locked->forceFill(['stock_restored_at' => now()])->save();
        });
    }
}
