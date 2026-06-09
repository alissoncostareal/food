<?php

namespace App\Services;

class MercadoPagoService
{
    public function isConfigured(): bool
    {
        return filled(config('services.mercado_pago.access_token'))
            && filled(config('services.mercado_pago.public_key'));
    }

    public function configurationStatus(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'environment' => config('services.mercado_pago.environment'),
            'webhook_url' => config('services.mercado_pago.webhook_url'),
            'missing' => array_values(array_filter([
                blank(config('services.mercado_pago.access_token')) ? 'MERCADO_PAGO_ACCESS_TOKEN' : null,
                blank(config('services.mercado_pago.public_key')) ? 'MERCADO_PAGO_PUBLIC_KEY' : null,
            ])),
        ];
    }
}
