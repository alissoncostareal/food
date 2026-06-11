<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;

class WhatsappOrderMessageTemplates
{
    public const PLACEHOLDERS = ['{nome}', '{pedido}', '{loja}'];

    public static function defaults(): array
    {
        return [
            'pending' => "Olá, {nome}!\n\n*{loja}*\nPedido #{pedido}\nRecebemos seu pedido e já avisamos a loja.",
            'preparing' => "Olá, {nome}!\n\n*{loja}*\nPedido #{pedido}\nSeu pedido foi confirmado e está *em preparo*.",
            'ready' => "Olá, {nome}!\n\n*{loja}*\nPedido #{pedido}\nSeu pedido está *pronto*!",
            'ready_pickup' => "Olá, {nome}!\n\n*{loja}*\nPedido #{pedido}\nSeu pedido está *pronto para retirada*!",
            'shipped' => "Olá, {nome}!\n\n*{loja}*\nPedido #{pedido}\nSeu pedido *saiu para entrega*!",
            'delivered' => "Olá, {nome}!\n\n*{loja}*\nPedido #{pedido}\nSeu pedido foi *entregue*. Bom apetite!",
            'canceled' => "Olá, {nome}!\n\n*{loja}*\nPedido #{pedido}\nSeu pedido foi *cancelado*. Se tiver dúvidas, fale com a loja.",
        ];
    }

    public static function labels(): array
    {
        return [
            'pending' => 'Pedido recebido',
            'preparing' => 'Em preparo',
            'ready' => 'Pronto (entrega)',
            'ready_pickup' => 'Pronto (retirada)',
            'shipped' => 'Saiu para entrega',
            'delivered' => 'Entregue',
            'canceled' => 'Cancelado',
        ];
    }

    public static function forStore(Store $store): array
    {
        $custom = is_array($store->whatsapp_order_messages) ? $store->whatsapp_order_messages : [];

        return array_merge(static::defaults(), array_filter($custom, fn ($value) => filled($value)));
    }

    public static function templateKeyForStatus(Order $order, string $status): string
    {
        $normalized = $status === 'cancelled' ? 'canceled' : $status;

        if ($normalized === 'ready' && $order->fulfillment_type === 'pickup') {
            return 'ready_pickup';
        }

        return $normalized;
    }

    public static function render(Store $store, Order $order, string $status): string
    {
        $key = static::templateKeyForStatus($order, $status);
        $templates = static::forStore($store);
        $template = $templates[$key] ?? $templates[$status] ?? static::defaults()[$key] ?? '';

        $customerName = trim((string) ($order->customer_name ?: $order->user?->name ?: 'Cliente'));
        $storeName = trim((string) ($store->name ?: 'Loja'));
        $orderCode = $order->display_code;

        return str_replace(
            ['{nome}', '{pedido}', '{loja}'],
            [$customerName, $orderCode, $storeName],
            $template
        );
    }
}
