<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderWhatsappNotifier;
use App\Services\StoreWhatsappConnectionService;
use App\Services\WhatsappProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseOrderWhatsapp extends Command
{
    protected $signature = 'orders:diagnose-whatsapp {order : ID do pedido}';

    protected $description = 'Lista por que o WhatsApp de status do pedido pode não estar sendo enviado';

    public function handle(
        OrderWhatsappNotifier $notifier,
        StoreWhatsappConnectionService $connection
    ): int {
        $order = Order::query()
            ->with(['store.plan', 'store.user', 'user'])
            ->find($this->argument('order'));

        if (! $order) {
            $this->error('Pedido não encontrado.');

            return self::FAILURE;
        }

        $store = $order->store;

        if (! $store) {
            $this->error('Loja do pedido não encontrada.');

            return self::FAILURE;
        }

        $this->info("Pedido #{$order->id} — status: {$order->status}");
        $this->line("Loja #{$store->id} ({$store->name}) — plano: ".($store->plan?->slug ?? 'sem plano'));
        $this->newLine();

        $checks = [
            'Plano com whatsapp_auto' => $store->canUseFeature('whatsapp_auto'),
            'WhatsApp da loja conectado' => $connection->isConnected($store),
            'Evolution/Meta configurado globalmente' => $notifier->canNotify($store, $order) || $connection->isConnected($store),
            'Telefone do cliente no pedido' => filled($order->customer_phone ?: $order->user?->phone),
            'Pode notificar este pedido (regras)' => $notifier->canNotify($store, $order),
            'Pode notificar pedido novo (wa.me)' => $notifier->shouldNotifyOnNewOrder($store, $order),
        ];

        foreach ($checks as $label => $ok) {
            $this->line(sprintf('  [%s] %s', $ok ? 'OK' : 'FALHA', $label));
        }

        $this->newLine();
        $this->line('Detalhes:');
        $this->line('  order_source: '.($order->order_source ?? 'web'));
        $this->line('  customer_phone: '.($order->customer_phone ?: $order->user?->phone ?: '(vazio)'));
        $this->line('  whatsapp_provider: '.$store->whatsappProvider());
        $this->line('  evolution_status: '.($store->evolution_status ?: '(vazio)'));
        $this->line('  evolution_instance: '.($store->evolution_instance_name ?: $store->slug));
        $this->line('  WHATSAPP_TEST_MODE: '.(config('services.evolution.test_mode') ? 'true' : 'false'));
        $this->line('  QUEUE_CONNECTION: '.config('queue.default'));

        $pendingJobs = DB::table('jobs')
            ->where('payload', 'like', '%SendOrderStatusWhatsapp%')
            ->count();

        $failedJobs = DB::table('failed_jobs')
            ->where('payload', 'like', '%SendOrderStatusWhatsapp%')
            ->count();

        $this->newLine();
        $this->line("Jobs SendOrderStatusWhatsapp na fila: {$pendingJobs}");
        $this->line("Jobs SendOrderStatusWhatsapp falhos: {$failedJobs}");

        if ($pendingJobs > 0) {
            $this->warn('Há jobs na fila — confira se o partiumenu-worker está rodando (queue:work).');
        }

        if ($store->evolution_status !== WhatsappProvisioningService::STATUS_CONNECTED && $store->usesEvolutionWhatsapp()) {
            $this->warn('Loja não está com evolution_status=connected. Reconecte o QR em Integrações → WhatsApp.');
        }

        return self::SUCCESS;
    }
}
