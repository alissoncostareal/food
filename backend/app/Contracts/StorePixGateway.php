<?php

namespace App\Contracts;

use App\Models\Order;
use App\Models\StorePaymentProvider;

interface StorePixGateway
{
    public function provider(): string;

    public function testConnection(StorePaymentProvider $connection): void;

    public function createPixCharge(
        Order $order,
        StorePaymentProvider $connection,
        ?string $idempotencySuffix = null
    ): PixChargeResult;

    public function fetchOrderStatus(StorePaymentProvider $connection, string $externalOrderId): ?string;

    public function handleWebhook(array $payload, string $eventType): ?int;

    public function supportsCardPayments(): bool;

    public function createCardToken(StorePaymentProvider $connection, array $card): string;

    public function createCardCharge(
        Order $order,
        StorePaymentProvider $connection,
        string $cardToken,
        int $installments = 1
    ): CardChargeResult;

    public function refundCharge(Order $order, StorePaymentProvider $connection): void;
}
