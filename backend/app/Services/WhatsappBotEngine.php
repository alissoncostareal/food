<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use App\Models\WhatsappSession;
use App\Support\BrazilPhone;

class WhatsappBotEngine
{
    public function welcomeMessage(Store $store): string
    {
        $custom = trim((string) $store->whatsapp_bot_welcome);

        if ($custom !== '') {
            return $custom;
        }

        return WhatsappBotMessageTemplates::renderWelcome($store);
    }

    public function tryReply(Store $store, WhatsappSession $session, string $message): ?string
    {
        $normalized = $this->normalize($message);

        if ($this->isCustomerOrderSubmission($normalized)) {
            return null;
        }

        $digitAction = WhatsappBotMenuConfig::actionForDigit($store, $normalized);

        if ($digitAction !== null) {
            return $this->replyForAction($store, $session, $digitAction);
        }

        if ($this->isMenuIntent($store, $normalized)) {
            return $this->replyForAction($store, $session, WhatsappBotMenuConfig::ACTION_MENU);
        }

        if ($this->isHoursIntent($store, $normalized)) {
            return $this->replyForAction($store, $session, WhatsappBotMenuConfig::ACTION_HOURS);
        }

        if ($this->isOrderStatusIntent($store, $normalized)) {
            return $this->replyForAction($store, $session, WhatsappBotMenuConfig::ACTION_ORDER);
        }

        if ($this->matchesAny($normalized, ['atendente', 'humano', 'pessoa', 'falar com'])) {
            return $this->replyForAction($store, $session, WhatsappBotMenuConfig::ACTION_HUMAN);
        }

        if ($this->matchesAny($normalized, ['oi', 'ola', 'olá', 'bom dia', 'boa tarde', 'boa noite', 'hey', 'hello'])) {
            return $this->welcomeMessage($store);
        }

        return null;
    }

    public function fallbackMenu(Store $store): string
    {
        return WhatsappBotMessageTemplates::renderFallback($store, $this->welcomeMessage($store));
    }

    public function humanModeAcknowledgement(Store $store): string
    {
        return WhatsappBotMessageTemplates::renderHumanReply($store);
    }

    public function enterHumanMode(WhatsappSession $session, Store $store): string
    {
        $session->forceFill([
            'state' => WhatsappSession::STATE_HUMAN,
            'human_mode_until' => now()->addHours((int) config('whatsapp.human_mode_hours', 4)),
        ])->save();

        return $this->humanModeAcknowledgement($store);
    }

    public function exitHumanMode(WhatsappSession $session): void
    {
        $session->forceFill([
            'state' => WhatsappSession::STATE_IDLE,
            'human_mode_until' => null,
        ])->save();
    }

    private function replyForAction(Store $store, WhatsappSession $session, string $action): string
    {
        return match ($action) {
            WhatsappBotMenuConfig::ACTION_MENU => WhatsappBotMessageTemplates::renderMenuReply($store),
            WhatsappBotMenuConfig::ACTION_HOURS => WhatsappBotMessageTemplates::renderHoursReply($store),
            WhatsappBotMenuConfig::ACTION_ORDER => WhatsappBotMessageTemplates::renderOrderReply(
                $store,
                $this->findLatestOrder($store, $session->customer_phone)
            ),
            WhatsappBotMenuConfig::ACTION_HUMAN => $this->enterHumanMode($session, $store),
            default => $this->fallbackMenu($store),
        };
    }

    private function findLatestOrder(Store $store, string $phone): ?Order
    {
        if (BrazilPhone::digits($phone) === '') {
            return null;
        }

        return Order::query()
            ->where('store_id', $store->id)
            ->with('user')
            ->latest()
            ->limit(100)
            ->get()
            ->first(fn (Order $order) => $this->orderMatchesPhone($order, $phone));
    }

    private function orderMatchesPhone(Order $order, string $phone): bool
    {
        if (BrazilPhone::matches($order->customer_phone, $phone)) {
            return true;
        }

        return BrazilPhone::matches($order->user?->phone, $phone);
    }

    private function normalize(string $message): string
    {
        $text = mb_strtolower(trim($message));

        return preg_replace('/\s+/', ' ', $text) ?? $text;
    }

    private function isCustomerOrderSubmission(string $normalized): bool
    {
        return str_contains($normalized, 'novo pedido')
            || str_contains($normalized, '*itens:*')
            || str_contains($normalized, '*cliente:*')
            || str_contains($normalized, 'whatsapp:*');
    }

    private function isMenuIntent(Store $store, string $normalized): bool
    {
        if (WhatsappBotMenuConfig::actionForDigit($store, $normalized) === WhatsappBotMenuConfig::ACTION_MENU) {
            return true;
        }

        return $this->matchesAny($normalized, [
            'menu',
            'cardapio',
            'cardápio',
            'ver cardapio',
            'ver cardápio',
            'link do cardapio',
            'link do cardápio',
            'fazer pedido',
            'fazer um pedido',
            'quero pedir',
            'como pedir',
        ]);
    }

    private function isHoursIntent(Store $store, string $normalized): bool
    {
        if (WhatsappBotMenuConfig::actionForDigit($store, $normalized) === WhatsappBotMenuConfig::ACTION_HOURS) {
            return true;
        }

        return $this->matchesAny($normalized, [
            'horario',
            'horário',
            'funcionamento',
            'que horas',
            'esta aberto',
            'está aberto',
            'ta aberto',
            'tá aberto',
            'aberto agora',
            'fecha hoje',
            'abre hoje',
        ]);
    }

    private function isOrderStatusIntent(Store $store, string $normalized): bool
    {
        if (WhatsappBotMenuConfig::actionForDigit($store, $normalized) === WhatsappBotMenuConfig::ACTION_ORDER) {
            return true;
        }

        if (in_array($normalized, ['status', 'andamento'], true)) {
            return true;
        }

        if (preg_match('/^(pedido|status)\??$/u', $normalized)) {
            return true;
        }

        return $this->matchesAny($normalized, [
            'status do pedido',
            'status do meu pedido',
            'meu pedido',
            'onde esta meu pedido',
            'onde está meu pedido',
            'acompanhar pedido',
            'rastrear pedido',
            'andamento do pedido',
            'pedido chegou',
            'chegou meu pedido',
        ]);
    }

    private function matchesAny(string $normalized, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($normalized === $needle || str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
