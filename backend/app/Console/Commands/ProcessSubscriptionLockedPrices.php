<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\PagarMeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSubscriptionLockedPrices extends Command
{
    protected $signature = 'subscriptions:process-locked-prices';

    protected $description = 'Atualiza assinaturas fundadoras para o preço regular após o período promocional';

    public function handle(PagarMeService $pagarMe): int
    {
        if (! $pagarMe->isConfigured()) {
            $this->warn('Pagar.me não configurado. Nada a processar.');

            return self::SUCCESS;
        }

        $processed = 0;
        $failed = 0;

        Store::query()
            ->with('plan')
            ->whereNotNull('subscription_price_until')
            ->where('subscription_price_until', '<=', now())
            ->whereNotNull('pagarme_subscription_id')
            ->whereNotNull('subscription_locked_price')
            ->orderBy('id')
            ->each(function (Store $store) use ($pagarMe, &$processed, &$failed) {
                $plan = $store->plan;

                if (! $plan) {
                    return;
                }

                $regularPrice = (float) $plan->price;
                $lockedPrice = (float) $store->subscription_locked_price;

                if ($regularPrice <= 0 || abs($regularPrice - $lockedPrice) < 0.01) {
                    $store->update([
                        'subscription_locked_price' => null,
                        'subscription_price_until' => null,
                    ]);

                    return;
                }

                try {
                    $pagarMe->updateSubscriptionPrice((string) $store->pagarme_subscription_id, $regularPrice);

                    $store->update([
                        'subscription_locked_price' => null,
                        'subscription_price_until' => null,
                    ]);

                    $processed++;
                    $this->line("Loja #{$store->id} ({$store->name}) atualizada para R$ {$regularPrice}.");
                } catch (Throwable $e) {
                    $failed++;
                    Log::warning('Falha ao atualizar preço promocional da assinatura', [
                        'store_id' => $store->id,
                        'subscription_id' => $store->pagarme_subscription_id,
                        'error' => $e->getMessage(),
                    ]);

                    $this->error("Loja #{$store->id}: {$e->getMessage()}");
                }
            });

        $this->info("Preços promocionais processados: {$processed}. Falhas: {$failed}.");

        return self::SUCCESS;
    }
}
