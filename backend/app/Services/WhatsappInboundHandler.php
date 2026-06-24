<?php

namespace App\Services;

use App\Models\Store;
use App\Models\WhatsappConversationMessage;
use App\Models\WhatsappSession;
use Illuminate\Support\Facades\Log;

class WhatsappInboundHandler
{
    public function __construct(
        private readonly WhatsappEvolutionPayload $payloadParser,
        private readonly WhatsappBotEngine $botEngine,
        private readonly WhatsappAiAssistant $aiAssistant,
        private readonly EvolutionService $evolution,
        private readonly StoreWhatsappMessenger $messenger,
    ) {}

    public function handle(Store $store, array $payload, string $event = ''): void
    {
        $store->loadMissing('plan');

        if (! $store->canUseFeature('whatsapp_auto')) {
            return;
        }

        if (! $this->payloadParser->isInboundMessageEvent($event)) {
            return;
        }

        if (! $this->evolution->isConfigured()) {
            return;
        }

        if ($store->evolution_status !== WhatsappProvisioningService::STATUS_CONNECTED
            && ! $this->evolution->isTestMode()) {
            return;
        }

        foreach ($this->payloadParser->extractInboundMessages($payload) as $message) {
            $this->handleInboundMessage($store, $message['phone'], $message['text']);
        }
    }

    public function handleInboundMessage(Store $store, string $phone, string $text): void
    {
        $store->loadMissing('plan');

        if (! $store->canUseFeature('whatsapp_auto')) {
            return;
        }

        $phone = $this->evolution->normalizePhonePublic($phone);
        $session = WhatsappSession::forStorePhone($store, $phone);
        $session->forceFill(['last_inbound_at' => now()])->save();
        $this->logMessage($session, 'inbound', 'customer', $text);

        if (! $store->canUseFeature('whatsapp_bot') || ! $store->whatsapp_bot_enabled) {
            return;
        }

        $normalized = mb_strtolower(trim($text));

        if ($session->isHumanMode()) {
            if (in_array($normalized, ['menu', 'bot', 'voltar'], true)) {
                $this->botEngine->exitHumanMode($session);
                $reply = $this->botEngine->welcomeMessage($store);
                $this->sendReply($store, $session, $phone, $reply, 'bot');
            }

            return;
        }

        if (in_array($normalized, ['menu', 'bot', 'voltar'], true)) {
            $this->botEngine->exitHumanMode($session);
            $reply = $this->botEngine->welcomeMessage($store);
            $this->sendReply($store, $session, $phone, $reply, 'bot');

            return;
        }

        if ($this->botEngine->shouldSuppressReply($text)) {
            return;
        }

        $reply = $this->botEngine->tryReply($store, $session, $text);
        $source = 'bot';

        if (! $reply && $this->aiAssistant->canReply($store)) {
            $reply = $this->aiAssistant->reply($store, $session, $text);
            $source = 'ai';
        }

        if (! $reply) {
            $reply = $this->botEngine->fallbackMenu($store);
            $source = 'bot';
        }

        $this->sendReply($store, $session, $phone, $reply, $source);
    }

    private function sendReply(Store $store, WhatsappSession $session, string $phone, string $reply, string $source): void
    {
        try {
            $this->messenger->sendText($store, $phone, $reply);
            $session->forceFill(['last_outbound_at' => now()])->save();
            $this->logMessage($session, 'outbound', $source, $reply);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp bot reply failed', [
                'store_id' => $store->id,
                'phone' => $phone,
                'provider' => $store->whatsappProvider(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function logMessage(WhatsappSession $session, string $direction, string $source, string $body): void
    {
        WhatsappConversationMessage::query()->create([
            'whatsapp_session_id' => $session->id,
            'direction' => $direction,
            'source' => $source,
            'body' => mb_substr(trim($body), 0, 4000),
        ]);

        $ttlHours = (int) config('whatsapp.session_ttl_hours', 24);
        $session->messages()
            ->where('created_at', '<', now()->subHours($ttlHours))
            ->delete();
    }
}
