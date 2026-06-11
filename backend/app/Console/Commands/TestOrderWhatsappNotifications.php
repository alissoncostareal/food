<?php

namespace App\Console\Commands;

use App\Events\NewOrderPlaced;
use App\Events\OrderUpdated;
use App\Models\Order;
use App\Models\Store;
use App\Services\WhatsappProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class TestOrderWhatsappNotifications extends Command
{
    protected $signature = 'orders:test-whatsapp {--store= : ID da loja} {--order= : ID do pedido existente}';

    protected $description = 'Simula envio de status de pedido via WhatsApp (WHATSAPP_TEST_MODE grava no log)';

    public function handle(): int
    {
        Config::set('services.evolution.test_mode', true);
        Config::set('services.evolution.enabled', true);

        $store = $this->resolveStore();

        if (! $store) {
            $this->error('Nenhuma loja encontrada.');

            return self::FAILURE;
        }

        $store->update([
            'evolution_status' => WhatsappProvisioningService::STATUS_CONNECTED,
            'evolution_instance_name' => $store->evolution_instance_name ?: $store->slug,
        ]);

        $store->refresh()->load('plan');

        $this->info("Loja #{$store->id} ({$store->name}) — plano: ".($store->plan?->slug ?? 'sem plano'));

        if (! $store->canUseFeature('whatsapp_auto')) {
            $this->warn('Plano sem whatsapp_auto; o teste continua forçando test_mode no log.');
        }

        $order = $this->resolveOrder($store);

        if (! $order) {
            $this->error('Não foi possível criar ou encontrar pedido de teste.');

            return self::FAILURE;
        }

        $this->info("Pedido #{$order->id} — telefone: {$order->customer_phone}");
        $this->line('WHATSAPP_TEST_MODE='.(config('services.evolution.test_mode') ? 'true' : 'false'));
        $this->newLine();

        $order->update(['status' => 'pending']);
        $order->refresh()->load(['items.product', 'user', 'store', 'deliveryArea', 'coupon']);

        event(new NewOrderPlaced($order));
        $this->line('[event] NewOrderPlaced → pending');

        $statuses = ['preparing', 'ready', 'shipped', 'delivered'];

        foreach ($statuses as $status) {
            $previousStatus = $order->status;
            $order->update(['status' => $status]);
            $order->refresh()->load(['items.product', 'user', 'store', 'deliveryArea', 'coupon']);

            event(new OrderUpdated($order, $previousStatus));
            $this->line("[event] OrderUpdated {$previousStatus} → {$status}");
        }

        $this->newLine();
        $this->info('Conferir: storage/logs/laravel.log (busque "WhatsApp test mode: message logged")');

        return self::SUCCESS;
    }

    private function resolveStore(): ?Store
    {
        $storeId = $this->option('store');

        if ($storeId) {
            return Store::query()->with('plan')->find($storeId);
        }

        return Store::query()
            ->with('plan')
            ->whereHas('plan', fn ($q) => $q->whereIn('slug', ['pro', 'premium']))
            ->first()
            ?? Store::query()->with('plan')->first();
    }

    private function resolveOrder(Store $store): ?Order
    {
        $orderId = $this->option('order');

        if ($orderId) {
            $order = Order::query()->where('store_id', $store->id)->find($orderId);

            if ($order && blank($order->customer_phone)) {
                $order->update(['customer_phone' => '11999998888']);
            }

            return $order?->fresh();
        }

        $order = Order::query()
            ->where('store_id', $store->id)
            ->whereNotNull('customer_phone')
            ->latest()
            ->first();

        if ($order) {
            return $order;
        }

        return Order::query()->create([
            'store_id' => $store->id,
            'customer_name' => 'Cliente Teste WhatsApp',
            'customer_phone' => '11999998888',
            'total_amount' => 45.90,
            'delivery_fee' => 5,
            'status' => 'pending',
            'type' => 'sale',
            'fulfillment_type' => 'delivery',
            'payment_method' => 'pix',
            'address' => 'Rua Teste, 100',
        ]);
    }
}
