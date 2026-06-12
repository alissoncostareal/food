<?php

namespace App\Services\Payments;

use App\Contracts\StorePixGateway;
use App\Models\StorePaymentProvider;
use App\Services\Payments\Gateways\AsaasStorePixGateway;
use App\Services\Payments\Gateways\MercadoPagoStorePixGateway;
use App\Services\Payments\Gateways\PagarmeStorePixGateway;
use RuntimeException;

class StorePixGatewayResolver
{
    public function resolve(StorePaymentProvider $connection): StorePixGateway
    {
        return match ($connection->provider) {
            'pagarme' => app(PagarmeStorePixGateway::class),
            'mercadopago' => app(MercadoPagoStorePixGateway::class),
            'asaas' => app(AsaasStorePixGateway::class),
            default => throw new RuntimeException("Gateway de Pix não suportado: {$connection->provider}"),
        };
    }
}
