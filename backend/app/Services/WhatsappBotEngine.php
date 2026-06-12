<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;
use App\Models\WhatsappSession;

class WhatsappBotEngine
{
    public function welcomeMessage(Store $store): string
    {
        $custom = trim((string) $store->whatsapp_bot_welcome);

        if ($custom !== '') {
            return $custom;
        }

        $lines = [
            "Olá! Sou o assistente da {$store->name}.",
            '',
            '1 - Ver cardápio',
            '2 - Horário de funcionamento',
            '3 - Status do meu pedido',
            '4 - Falar com atendente',
        ];

        if ($store->whatsappAiActive()) {
            $lines[] = '';
            $lines[] = 'Ou escreva sua dúvida sobre a loja.';
        }

        return implode("\n", $lines);
    }

    public function tryReply(Store $store, WhatsappSession $session, string $message): ?string
    {
        $normalized = $this->normalize($message);

        if ($this->isCustomerOrderSubmission($normalized)) {
            return null;
        }

        if ($this->isMenuIntent($normalized)) {
            return $this->menuLinkReply($store);
        }

        if ($this->isHoursIntent($normalized)) {
            return $this->hoursReply($store);
        }

        if ($this->isOrderStatusIntent($normalized)) {
            return $this->orderStatusReply($store, $session->customer_phone);
        }

        if ($this->matchesAny($normalized, ['atendente', 'humano', 'pessoa', 'falar com'])) {
            return $this->enterHumanMode($session);
        }

        if ($this->matchesAny($normalized, ['oi', 'ola', 'olá', 'bom dia', 'boa tarde', 'boa noite', 'hey', 'hello'])) {
            return $this->welcomeMessage($store);
        }

        return match ($normalized) {
            '1' => $this->menuLinkReply($store),
            '2' => $this->hoursReply($store),
            '3' => $this->orderStatusReply($store, $session->customer_phone),
            '4' => $this->enterHumanMode($session),
            default => null,
        };
    }

    public function fallbackMenu(Store $store): string
    {
        return "Não entendi. Escolha uma opção:\n\n".$this->welcomeMessage($store);
    }

    public function humanModeAcknowledgement(): string
    {
        return 'Certo! Um atendente vai continuar por aqui em breve. Para voltar ao menu automático, digite *menu*.';
    }

    public function enterHumanMode(WhatsappSession $session): string
    {
        $session->forceFill([
            'state' => WhatsappSession::STATE_HUMAN,
            'human_mode_until' => now()->addHours((int) config('whatsapp.human_mode_hours', 4)),
        ])->save();

        return $this->humanModeAcknowledgement();
    }

    public function exitHumanMode(WhatsappSession $session): void
    {
        $session->forceFill([
            'state' => WhatsappSession::STATE_IDLE,
            'human_mode_until' => null,
        ])->save();
    }

    private function menuLinkReply(Store $store): string
    {
        return "Faça seu pedido pelo cardápio digital:\n{$store->menuUrl()}";
    }

    private function hoursReply(Store $store): string
    {
        $status = $store->opening_status ?? [];
        $message = $status['message'] ?? ($store->is_open_now ? 'Aberto agora' : 'Fechado no momento');
        $next = $status['next_opening'] ?? null;

        $lines = ["*Horário — {$store->name}*", $message];

        if ($next) {
            $lines[] = "Próxima abertura: {$next}";
        }

        $hours = $store->business_hours;

        if (is_array($hours) && $hours !== []) {
            $lines[] = '';
            $lines[] = 'Confira os horários completos no cardápio.';
        }

        return implode("\n", $lines);
    }

    private function orderStatusReply(Store $store, string $phone): string
    {
        $order = $this->findLatestOrder($store, $phone);

        if (! $order) {
            return "Não encontrei pedidos recentes para este número.\nFaça um pedido pelo cardápio:\n{$store->menuUrl()}";
        }

        $statusLabel = match ($order->status) {
            'pending' => 'Aguardando confirmação',
            'preparing' => 'Em preparo',
            'ready' => 'Pronto',
            'shipped' => 'Saiu para entrega',
            'delivered' => 'Entregue',
            'canceled', 'cancelled' => 'Cancelado',
            default => ucfirst((string) $order->status),
        };

        $payment = $order->payment_status === 'awaiting_payment'
            ? "\nPagamento: aguardando confirmação."
            : '';

        return "Pedido #{$order->display_code}\nStatus: {$statusLabel}{$payment}";
    }

    private function findLatestOrder(Store $store, string $phone): ?Order
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        $suffix = strlen($digits) >= 11 ? substr($digits, -11) : $digits;

        return Order::query()
            ->where('store_id', $store->id)
            ->whereNotIn('status', ['canceled', 'cancelled'])
            ->where(function ($query) use ($suffix, $digits) {
                $query->where('customer_phone', 'like', '%'.$suffix)
                    ->orWhere('customer_phone', 'like', '%'.$digits);
            })
            ->latest()
            ->first();
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

    private function isMenuIntent(string $normalized): bool
    {
        if (in_array($normalized, ['1', 'menu', 'cardapio', 'cardápio'], true)) {
            return true;
        }

        return $this->matchesAny($normalized, [
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

    private function isHoursIntent(string $normalized): bool
    {
        if (in_array($normalized, ['2'], true)) {
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

    private function isOrderStatusIntent(string $normalized): bool
    {
        if (in_array($normalized, ['3', 'status', 'andamento'], true)) {
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
