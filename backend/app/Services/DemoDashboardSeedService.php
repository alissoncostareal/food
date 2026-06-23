<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DemoDashboardSeedService
{
    public const ORDER_SOURCE = 'demo_dashboard';

    private const CUSTOMER_NAMES = [
        'Ana S.',
        'Bruno M.',
        'Carla R.',
        'Diego L.',
        'Elena P.',
        'Felipe T.',
        'Gabriela N.',
        'Henrique V.',
    ];

    private const DELIVERED_STATUSES = ['delivered', 'delivered', 'delivered', 'preparing', 'ready'];

    /**
     * @return array{cleared: int, created: int, today_revenue: float, pending_today: int}
     */
    public function seed(Store $store, bool $clearExisting = true): array
    {
        $products = $store->products()
            ->where('is_active', true)
            ->get();

        if ($products->isEmpty()) {
            throw ValidationException::withMessages([
                'store' => ['Cadastre produtos ativos na loja antes de popular o dashboard.'],
            ]);
        }

        $timezone = config('app.timezone', 'America/Fortaleza');
        $now = CarbonImmutable::now($timezone);

        return DB::transaction(function () use ($store, $products, $clearExisting, $now) {
            $cleared = $clearExisting ? $this->clear($store) : 0;
            $created = 0;
            $pendingToday = 0;

            for ($day = 6; $day >= 0; $day--) {
                $ordersForDay = $day === 0 ? random_int(8, 14) : random_int(4, 10);

                for ($index = 0; $index < $ordersForDay; $index++) {
                    $product = $products->random();
                    $quantity = random_int(1, 3);
                    $subtotal = round((float) $product->price * $quantity, 2);
                    $deliveryFee = random_int(0, 1) ? 5.00 : 0.00;
                    $totalAmount = round($subtotal + $deliveryFee, 2);
                    $hour = random_int(11, 22);
                    $createdAt = $now->subDays($day)->setTime($hour, random_int(0, 59));
                    $status = $day === 0 && $index < 3
                        ? 'pending'
                        : self::DELIVERED_STATUSES[array_rand(self::DELIVERED_STATUSES)];

                    if ($day === 0 && $status === 'pending') {
                        $pendingToday++;
                    }

                    $order = Order::create([
                        'store_id' => $store->id,
                        'order_source' => self::ORDER_SOURCE,
                        'customer_name' => self::CUSTOMER_NAMES[array_rand(self::CUSTOMER_NAMES)],
                        'customer_phone' => '85999' . str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
                        'status' => $status,
                        'type' => 'sale',
                        'fulfillment_type' => $deliveryFee > 0 ? 'delivery' : 'pickup',
                        'address' => $deliveryFee > 0 ? 'Rua Demo, 100 - Centro' : 'Retirada no local',
                        'payment_method' => 'cash',
                        'payment_status' => OrderPixPaymentService::STATUS_NOT_REQUIRED,
                        'payment_channel' => 'offline',
                        'total_amount' => $totalAmount,
                        'delivery_fee' => $deliveryFee,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt->addMinutes(random_int(5, 45)),
                    ]);

                    $order->items()->create([
                        'product_id' => $product->id,
                        'quantity' => $quantity,
                        'price' => $product->price,
                        'subtotal' => $subtotal,
                    ]);

                    $created++;
                }
            }

            $todayRevenue = (float) Order::query()
                ->where('store_id', $store->id)
                ->where('order_source', self::ORDER_SOURCE)
                ->whereDate('created_at', $now->toDateString())
                ->whereNotIn('status', ['canceled', 'cancelled'])
                ->sum('total_amount');

            return [
                'cleared' => $cleared,
                'created' => $created,
                'today_revenue' => $todayRevenue,
                'pending_today' => $pendingToday,
            ];
        });
    }

    public function clear(Store $store): int
    {
        $orderIds = Order::query()
            ->where('store_id', $store->id)
            ->where('order_source', self::ORDER_SOURCE)
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return 0;
        }

        DB::table('order_items')->whereIn('order_id', $orderIds)->delete();

        return Order::query()
            ->whereIn('id', $orderIds)
            ->delete();
    }
}
