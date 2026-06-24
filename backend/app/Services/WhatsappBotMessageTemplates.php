<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;

class WhatsappBotMessageTemplates
{
    public const PLACEHOLDERS = [
        '{loja}',
        '{menu_url}',
        '{status}',
        '{next_opening}',
        '{pedido}',
        '{payment}',
        '{detalhes}',
        '{welcome}',
    ];

    public static function defaults(): array
    {
        return [
            'welcome_intro' => 'Olá! Sou o assistente da {loja}.',
            'option_ai_hint' => 'Ou escreva sua dúvida sobre a loja.',
            'reply_menu' => "Faça seu pedido pelo cardápio digital:\n{menu_url}",
            'reply_hours' => "*Horário — {loja}*\n{status}",
            'reply_order_found' => "*Pedido #{pedido}*\nStatus: {status}{payment}\n\n{detalhes}",
            'reply_order_missing' => "Não encontrei pedidos recentes para este número.\nFaça um pedido pelo cardápio:\n{menu_url}",
            'reply_human' => 'Certo! Um atendente vai continuar por aqui em breve. Para voltar ao menu automático, digite *menu*.',
            'reply_fallback' => "Não entendi. Escolha uma opção:\n\n{welcome}",
        ];
    }

    public static function labels(): array
    {
        return [
            'welcome_intro' => 'Saudação inicial',
            'option_ai_hint' => 'Dica da IA (Premium)',
            'reply_menu' => 'Resposta · Cardápio',
            'reply_hours' => 'Resposta · Horário',
            'reply_order_found' => 'Resposta · Pedido encontrado',
            'reply_order_missing' => 'Resposta · Pedido não encontrado',
            'reply_human' => 'Resposta · Atendente humano',
            'reply_fallback' => 'Resposta · Não entendi',
        ];
    }

    public static function forStore(Store $store): array
    {
        $custom = is_array($store->whatsapp_bot_messages) ? $store->whatsapp_bot_messages : [];

        return array_merge(static::defaults(), array_filter($custom, fn ($value) => filled($value)));
    }

    public static function renderWelcome(Store $store): string
    {
        $templates = static::forStore($store);
        $customIntro = trim((string) $store->whatsapp_bot_welcome);
        $intro = $customIntro !== ''
            ? static::replaceStoreTokens($customIntro, $store)
            : static::replaceStoreTokens($templates['welcome_intro'], $store);

        $lines = [
            $intro,
            '',
            ...WhatsappBotMenuConfig::menuLines($store),
        ];

        $keywordHints = static::renderKeywordHints($store);

        if ($keywordHints !== '') {
            $lines[] = '';
            $lines[] = $keywordHints;
        }

        if ($store->whatsappAiActive()) {
            $lines[] = '';
            $lines[] = $templates['option_ai_hint'];
        }

        return implode("\n", $lines);
    }

    public static function renderKeywordHints(Store $store): string
    {
        $keywords = [];
        $enabledActions = collect(WhatsappBotMenuConfig::enabledOptions($store))->pluck('action');

        if ($enabledActions->contains(WhatsappBotMenuConfig::ACTION_MENU)) {
            $keywords[] = 'cardápio';
        }

        if ($enabledActions->contains(WhatsappBotMenuConfig::ACTION_HOURS)) {
            $keywords[] = 'horário';
        }

        if ($enabledActions->contains(WhatsappBotMenuConfig::ACTION_ORDER)) {
            $keywords[] = 'pedido';
        }

        if ($enabledActions->contains(WhatsappBotMenuConfig::ACTION_HUMAN)) {
            $keywords[] = 'atendente';
        }

        if ($keywords === []) {
            return '';
        }

        return 'Você também pode escrever: *'.implode('*, *', $keywords).'*.';
    }

    public static function renderMenuReply(Store $store): string
    {
        return static::replaceStoreTokens(static::forStore($store)['reply_menu'], $store);
    }

    public static function renderHoursReply(Store $store): string
    {
        $templates = static::forStore($store);
        $status = $store->opening_status ?? [];
        $message = $status['message'] ?? ($store->is_open_now ? 'Aberto agora' : 'Fechado no momento');
        $next = trim((string) ($status['next_opening'] ?? ''));

        $text = static::replaceStoreTokens($templates['reply_hours'], $store, [
            '{status}' => $message,
            '{next_opening}' => $next,
        ]);

        if ($next !== '' && ! str_contains($text, $next)) {
            $text .= "\nPróxima abertura: {$next}";
        }

        $hours = $store->business_hours;

        if (is_array($hours) && $hours !== [] && ! str_contains($text, 'horários completos')) {
            $text .= "\n\nConfira os horários completos no cardápio.";
        }

        return $text;
    }

    public static function renderOrderReply(Store $store, ?Order $order): string
    {
        $templates = static::forStore($store);

        if (! $order) {
            return static::replaceStoreTokens($templates['reply_order_missing'], $store);
        }

        $order->loadMissing(['items.product']);

        $statusLabel = static::orderStatusLabel($order);
        $payment = $order->payment_status === 'awaiting_payment'
            ? "\nPagamento: aguardando confirmação."
            : '';
        $details = static::formatOrderDetails($order);
        $template = $templates['reply_order_found'];

        $message = static::replaceStoreTokens($template, $store, [
            '{pedido}' => $order->display_code,
            '{status}' => $statusLabel,
            '{payment}' => $payment,
            '{detalhes}' => $details,
        ]);

        if (! str_contains($template, '{detalhes}') && filled($details)) {
            $message = rtrim($message)."\n\n".$details;
        }

        return $message;
    }

    public static function orderStatusLabel(Order $order): string
    {
        return match ($order->status) {
            'pending' => 'Aguardando confirmação',
            'preparing' => 'Em preparo',
            'ready' => 'Pronto',
            'shipped' => 'Saiu para entrega',
            'delivered' => 'Entregue',
            'canceled', 'cancelled' => 'Cancelado',
            default => ucfirst((string) $order->status),
        };
    }

    public static function formatOrderDetails(Order $order): string
    {
        $order->loadMissing(['items.product']);

        $lines = [];
        $lines[] = '*Itens:*';

        foreach ($order->items as $item) {
            $productName = $item->product->name ?? 'Produto';
            $lines[] = "{$item->quantity}x {$productName} - ".static::formatMoney($item->subtotal);

            $options = is_string($item->options) ? json_decode($item->options, true) : $item->options;

            foreach (($options ?? []) as $option) {
                $lines[] = '  + '.($option['name'] ?? 'Adicional')
                    .' ('.($option['group_name'] ?? 'Extra').') '
                    .static::formatMoney($option['additional_price'] ?? 0);
            }

            if (filled($item->observation)) {
                $lines[] = "  Obs: {$item->observation}";
            }
        }

        if ($order->items->isEmpty()) {
            $lines[] = 'Sem itens registrados';
        }

        $lines[] = '';
        $lines[] = '*Total:* '.static::formatMoney($order->total_amount);
        $lines[] = '*Tipo:* '.($order->fulfillment_type === 'pickup' ? 'Retirada no local' : 'Entrega');
        $lines[] = '*Pagamento:* '.static::paymentLabel($order->payment_method);

        if ($order->fulfillment_type === 'delivery' && filled($order->address)) {
            $address = trim($order->address);

            if (filled($order->district)) {
                $address .= ' - '.$order->district;
            }

            $lines[] = '*Endereço:* '.$address;
        }

        if (filled($order->observation)) {
            $lines[] = '*Obs.:* '.$order->observation;
        }

        return implode("\n", $lines);
    }

    private static function formatMoney(float|int|string|null $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }

    private static function paymentLabel(?string $method): string
    {
        return match ($method) {
            'cash' => 'Dinheiro',
            'debit_card' => 'Cartão de débito',
            'credit_card' => 'Cartão de crédito',
            'pix' => 'Pix na entrega',
            'pix_online' => 'Pix online',
            'credit_card_online' => 'Cartão online',
            default => 'Não informado',
        };
    }

    public static function renderHumanReply(Store $store): string
    {
        return static::forStore($store)['reply_human'];
    }

    public static function renderFallback(Store $store, string $welcome): string
    {
        return str_replace('{welcome}', $welcome, static::forStore($store)['reply_fallback']);
    }

    private static function replaceStoreTokens(string $template, Store $store, array $extra = []): string
    {
        $replacements = array_merge([
            '{loja}' => trim((string) ($store->name ?: 'Loja')),
            '{menu_url}' => $store->menuUrl(),
            '{status}' => '',
            '{next_opening}' => '',
            '{pedido}' => '',
            '{payment}' => '',
            '{detalhes}' => '',
            '{welcome}' => '',
        ], $extra);

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
