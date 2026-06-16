<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;

class WhatsappOrderUrlService
{
    public function buildForOrder(Order $order, ?Store $store = null): ?string
    {
        $store ??= $order->store;

        if (! $store) {
            return null;
        }

        $order->loadMissing(['items.product', 'user', 'deliveryArea', 'coupon']);

        $phone = preg_replace('/\D+/', '', (string) ($store->whatsapp_number ?? $store->whatsapp_phone ?? $store->phone ?? ''));

        if ($phone === '') {
            return null;
        }

        if (! str_starts_with($phone, '55')) {
            $phone = '55'.$phone;
        }

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($this->buildMessage($order));
    }

    public function ensureStoredForOrder(Order $order, ?Store $store = null): ?string
    {
        if (filled($order->whatsapp_url)) {
            return $order->whatsapp_url;
        }

        $url = $this->buildForOrder($order, $store);

        if ($url) {
            $order->update(['whatsapp_url' => $url]);
        }

        return $url;
    }

    private function buildMessage(Order $order): string
    {
        $lines = [];
        $lines[] = "*Novo pedido #{$order->id}*";
        $lines[] = '';
        $lines[] = "*Cliente:* {$order->customer_name}";
        $lines[] = "*WhatsApp:* {$order->customer_phone}";
        $lines[] = '*Tipo:* '.($order->fulfillment_type === 'pickup' ? 'Retirada no local' : 'Entrega');

        if ($order->fulfillment_type === 'delivery') {
            $lines[] = "*Endereço:* {$order->address}";

            if ($order->district) {
                $lines[] = "*Bairro:* {$order->district}";
            }
        }

        $lines[] = '*Pagamento:* '.$this->paymentLabel($order->payment_method);

        if ($order->payment_method === 'cash' && $order->change_for) {
            $lines[] = '*Troco para:* R$ '.number_format((float) $order->change_for, 2, ',', '.');
        }

        if ($order->observation) {
            $lines[] = "*Obs. pedido:* {$order->observation}";
        }

        $lines[] = '';
        $lines[] = '*Itens:*';

        foreach ($order->items as $item) {
            $productName = $item->product->name ?? 'Produto removido';

            $lines[] = "{$item->quantity}x {$productName} - R$ ".number_format((float) $item->subtotal, 2, ',', '.');

            $options = is_string($item->options) ? json_decode($item->options, true) : $item->options;

            foreach (($options ?? []) as $option) {
                $price = number_format((float) ($option['additional_price'] ?? 0), 2, ',', '.');
                $lines[] = "  + {$option['name']} ({$option['group_name']}) R$ {$price}";
            }

            if ($item->observation) {
                $lines[] = "  Obs: {$item->observation}";
            }
        }

        $subtotal = (float) $order->total_amount - (float) $order->delivery_fee + (float) $order->discount_amount;

        $lines[] = '';
        $lines[] = '*Subtotal:* R$ '.number_format($subtotal, 2, ',', '.');

        if ((float) $order->delivery_fee > 0) {
            $lines[] = '*Entrega:* R$ '.number_format((float) $order->delivery_fee, 2, ',', '.');
        } else {
            $lines[] = '*Entrega:* Retirada';
        }

        if ((float) $order->discount_amount > 0) {
            $couponCode = $order->coupon_display_code ?? $order->coupon_code ?? 'Cupom aplicado';

            $lines[] = "*Cupom:* {$couponCode}";
            $lines[] = '*Desconto:* - R$ '.number_format((float) $order->discount_amount, 2, ',', '.');
        }

        $lines[] = '*Total:* R$ '.number_format((float) $order->total_amount, 2, ',', '.');

        return implode("\n", $lines);
    }

    private function paymentLabel(?string $method): string
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
}
