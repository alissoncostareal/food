<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Store;

class IfoodOrderSyncService
{
    public function __construct(
        private readonly IfoodOrderActions $actions
    ) {
    }

    public function shouldSync(Order $order): bool
    {
        return $order->order_source === 'ifood'
            && filled($order->ifood_order_id)
            && $order->store?->isIfoodConnected();
    }

    public function syncLocalStatus(Order $order, string $newStatus, ?string $cancellationReason = null): void
    {
        if (! $this->shouldSync($order)) {
            return;
        }

        $store = $order->store;
        $ifoodOrderId = (string) $order->ifood_order_id;

        match ($newStatus) {
            'preparing' => $this->acceptOnIfood($order, $store, $ifoodOrderId),
            'ready' => $this->markReadyOnIfood($order, $store, $ifoodOrderId),
            'shipped' => $this->dispatchOnIfood($order, $store, $ifoodOrderId),
            'delivered' => $this->concludeOnIfood($order, $store, $ifoodOrderId),
            'canceled' => $this->cancelOnIfood($store, $ifoodOrderId, $cancellationReason),
            default => null,
        };
    }

    public function autoAcceptIfEnabled(Order $order, Store $store): Order
    {
        if (! $store->ifood_auto_confirm || ! filled($order->ifood_order_id)) {
            return $order;
        }

        $this->acceptOnIfood($order, $store, (string) $order->ifood_order_id);

        $order->update([
            'status' => 'preparing',
            'ifood_confirmed_at' => now(),
        ]);

        return $order->fresh();
    }

    private function acceptOnIfood(Order $order, Store $store, string $ifoodOrderId): void
    {
        // Sempre chama confirm no iFood ao aceitar localmente — 409 = já confirmado.
        $this->actions->confirm($store, $ifoodOrderId);

        if (blank($order->ifood_confirmed_at)) {
            $order->forceFill(['ifood_confirmed_at' => now()])->save();
        }

        $this->actions->startPreparation($store, $ifoodOrderId);
    }

    private function markReadyOnIfood(Order $order, Store $store, string $ifoodOrderId): void
    {
        $this->actions->readyToPickup($store, $ifoodOrderId);
    }

    private function dispatchOnIfood(Order $order, Store $store, string $ifoodOrderId): void
    {
        if (! $this->usesMerchantDispatch($order)) {
            return;
        }

        $this->actions->dispatch($store, $ifoodOrderId);
    }

    private function cancelOnIfood(Store $store, string $ifoodOrderId, ?string $cancellationReason): void
    {
        if (blank($cancellationReason)) {
            throw new \InvalidArgumentException('Informe o motivo de cancelamento iFood.');
        }

        $this->actions->requestCancellation($store, $ifoodOrderId, $cancellationReason);
    }

    private function concludeOnIfood(Order $order, Store $store, string $ifoodOrderId): void
    {
        // O iFood não permite concluir pedidos marketplace via API.
        // - MERCHANT: conclui automaticamente após dispatch (ou timeout).
        // - IFOOD: motoboy confirma no app.
        // - TAKEOUT: conclui na retirada.
        // Finalizar aqui é registro local; evento CONCLUDED sincroniza via polling.
    }

    private function usesMerchantDispatch(Order $order): bool
    {
        if (strtoupper((string) $order->ifood_delivered_by) === 'MERCHANT') {
            return true;
        }

        return strtoupper((string) $order->ifood_order_type) === 'DELIVERY'
            && strtoupper((string) $order->ifood_delivered_by) !== 'IFOOD';
    }
}
