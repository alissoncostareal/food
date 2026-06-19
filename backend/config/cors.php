<?php

$configuredOrigins = array_filter(array_map('trim', explode(',', implode(',', array_filter([
    env('CORS_ALLOWED_ORIGINS'),
    env('FRONTEND_URL'),
    env('APP_FRONTEND_URL'),
    env('APP_MENU_URL'),
    env('ADMIN_DASHBOARD_URL'),
])))));

$defaultOrigins = [
    'http://localhost:3000',
    'http://localhost:5173',
    'http://localhost:5174',
    'http://localhost:5175',
    'http://localhost:5176',
    'http://localhost:8000',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:5173',
    'http://127.0.0.1:5174',
    'http://127.0.0.1:5175',
    'http://127.0.0.1:5176',
    'http://127.0.0.1:8000',
    'https://partiumenu.com.br',
    'https://www.partiumenu.com.br',
    'https://admin.partiumenu.com.br',
    'https://app.partiumenu.com.br',
    'https://admin.domain.com.br',
    'https://app.domain.com.br',
];

return [
    'paths' => [
        'api/*',
        'api/broadcasting/auth',
        'broadcasting/auth',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter([
        ...$defaultOrigins,
        ...$configuredOrigins,
    ]))),

    'allowed_origins_patterns' => array_filter(array_map('trim', explode(',', env(
        'CORS_ALLOWED_ORIGIN_PATTERNS',
        '#^https://([a-z0-9-]+\.)?partiumenu\.com\.br$#,#^https://([a-z0-9-]+\.)?domain\.com\.br$#'
    )))),

    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'Authorization',
        'Accept',
        'Origin',
        'Cache-Control',
        'Pragma',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
