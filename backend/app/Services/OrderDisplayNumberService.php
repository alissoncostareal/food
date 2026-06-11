<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderDisplayNumberService
{
    private const MIN = 1000;

    private const MAX = 9999;

    private const MAX_ATTEMPTS = 50;

    /** Evita colisão com pedidos recentes da mesma loja (como o iFood reutiliza com o tempo). */
    private const COLLISION_WINDOW_DAYS = 7;

    public function assignNext(Order $order): string
    {
        if (filled($order->display_number)) {
            return (string) $order->display_number;
        }

        if (blank($order->store_id)) {
            throw new \RuntimeException('Pedido sem loja para gerar número de exibição.');
        }

        return DB::transaction(function () use ($order) {
            Order::query()
                ->where('store_id', $order->store_id)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->limit(1)
                ->value('id');

            for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
                $candidate = (string) random_int(self::MIN, self::MAX);

                $exists = Order::query()
                    ->where('store_id', $order->store_id)
                    ->where('display_number', $candidate)
                    ->where('created_at', '>=', now()->subDays(self::COLLISION_WINDOW_DAYS))
                    ->exists();

                if (! $exists) {
                    return $candidate;
                }
            }

            throw new \RuntimeException('Não foi possível gerar um número de pedido único. Tente novamente.');
        });
    }

    /** Usa o número exibido no app iFood (displayId / shortReference). */
    public function fromIfoodDisplayId(string $displayId): string
    {
        $normalized = trim($displayId);

        if ($normalized === '') {
            throw new \InvalidArgumentException('Número iFood vazio.');
        }

        return substr($normalized, 0, 32);
    }

    /** Gera número aleatório para backfill/migrations (sem janela de colisão). */
    public function assignUniqueForStore(int $storeId, array $alreadyUsed = []): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = (string) random_int(self::MIN, self::MAX);

            if (in_array($candidate, $alreadyUsed, true)) {
                continue;
            }

            $exists = Order::query()
                ->where('store_id', $storeId)
                ->where('display_number', $candidate)
                ->exists();

            if (! $exists) {
                return $candidate;
            }

            $alreadyUsed[] = $candidate;
        }

        throw new \RuntimeException("Não foi possível gerar número para loja {$storeId}.");
    }
}
