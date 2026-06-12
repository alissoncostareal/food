<?php

return [
    'pix_expires_in' => (int) env('PAYMENTS_PIX_EXPIRES_IN', 1800),

    'unpaid_order_ttl_minutes' => (int) env('PAYMENTS_UNPAID_TTL_MINUTES', 30),

    'polling_interval_ms' => (int) env('PAYMENTS_POLLING_INTERVAL_MS', 3000),

    'allow_platform_fallback' => (bool) env('PAYMENTS_ALLOW_PLATFORM_FALLBACK', false),

    'offline_methods' => ['pix', 'cash', 'debit_card', 'credit_card'],

    'online_methods' => ['pix_online', 'credit_card_online'],

    'providers' => [
        'pagarme' => [
            'label' => 'Pagar.me',
            'description' => 'Pix e cartão online com a conta comercial da sua loja no Pagar.me.',
            'connection_methods' => [
                'api_keys' => [
                    'label' => 'Chaves da API',
                    'fields' => [
                        'secret_key' => ['label' => 'Secret key', 'type' => 'password', 'required' => true],
                        'public_key' => ['label' => 'Public key', 'type' => 'text', 'required' => true],
                        'webhook_secret' => [
                            'label' => 'Webhook secret',
                            'type' => 'password',
                            'required' => false,
                            'hint' => 'Segredo gerado ao cadastrar a URL de webhook no painel Pagar.me da sua loja.',
                        ],
                    ],
                ],
            ],
        ],
        'mercadopago' => [
            'label' => 'Mercado Pago',
            'description' => 'Pix online com Access Token da sua conta Mercado Pago.',
            'connection_methods' => [
                'access_token' => [
                    'label' => 'Access Token',
                    'fields' => [
                        'access_token' => ['label' => 'Access Token', 'type' => 'password', 'required' => true],
                        'public_key' => ['label' => 'Public Key (opcional)', 'type' => 'text', 'required' => false],
                    ],
                ],
            ],
        ],
        'asaas' => [
            'label' => 'Asaas',
            'description' => 'Pix online com API Key da sua conta Asaas.',
            'connection_methods' => [
                'api_key' => [
                    'label' => 'API Key',
                    'fields' => [
                        'api_key' => ['label' => 'API Key', 'type' => 'password', 'required' => true],
                        'environment' => ['label' => 'Ambiente', 'type' => 'select', 'options' => ['sandbox', 'production'], 'required' => true],
                    ],
                ],
            ],
        ],
    ],
];
