<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWhatsappInboundMessage;
use App\Models\Store;
use App\Models\WhatsappConversationMessage;
use App\Services\WhatsappProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class TestWhatsappBot extends Command
{
    protected $signature = 'whatsapp:test-bot
        {--store= : ID da loja}
        {--phone=5511999999999 : Telefone simulado do cliente}
        {--text=1 : Texto da mensagem inbound}';

    protected $description = 'Simula mensagem inbound no bot WhatsApp (WHATSAPP_TEST_MODE grava resposta no log)';

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
            'whatsapp_bot_enabled' => true,
        ]);

        $store->refresh()->load('plan');

        $phone = preg_replace('/\D+/', '', (string) $this->option('phone')) ?: '5511999999999';
        $text = (string) $this->option('text');

        $this->info("Loja #{$store->id} ({$store->name})");
        $this->line("WHATSAPP_TEST_MODE=true — inbound de {$phone}: \"{$text}\"");
        $this->newLine();

        $payload = [
            'event' => 'messages.upsert',
            'data' => [
                'key' => [
                    'remoteJid' => preg_replace('/^55/', '', $phone).'@s.whatsapp.net',
                    'fromMe' => false,
                ],
                'message' => [
                    'conversation' => $text,
                ],
            ],
        ];

        ProcessWhatsappInboundMessage::dispatchSync($store->id, $payload, 'messages.upsert');

        $lastReply = WhatsappConversationMessage::query()
            ->whereHas('session', fn ($q) => $q->where('store_id', $store->id)->where('customer_phone', $phone))
            ->where('direction', 'outbound')
            ->latest('id')
            ->value('body');

        if ($lastReply) {
            $this->info('Resposta do bot:');
            $this->line($lastReply);

            return self::SUCCESS;
        }

        $this->warn('Nenhuma resposta registrada. Verifique plano (whatsapp_bot), bot ativo e logs.');

        return self::FAILURE;
    }

    private function resolveStore(): ?Store
    {
        $id = $this->option('store');

        if ($id) {
            return Store::query()->find($id);
        }

        return Store::query()->orderBy('id')->first();
    }
}
