<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'admin' => [
        'url' => env('ADMIN_DASHBOARD_URL', env('FRONTEND_URL', 'http://localhost:5175')),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

    'geocoding' => [
        'user_agent' => env('GEOCODING_USER_AGENT', env('APP_NAME', 'PartiuMenu') . ' (' . env('APP_URL', 'http://localhost:8000') . ')'),
        'geoapify_api_key' => env('GEOAPIFY_API_KEY'),
        'mapbox_token' => env('MAPBOX_ACCESS_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pagarme' => [
        'environment' => env('PAGARME_ENVIRONMENT', 'sandbox'),
        'base_url' => env('PAGARME_BASE_URL', 'https://api.pagar.me/core/v5'),
        'account_id' => env('PAGARME_ACCOUNT_ID'),
        'public_key' => env('PAGARME_PUBLIC_KEY'),
        'secret_key' => env('PAGARME_SECRET_KEY'),
        'webhook_url' => env('PAGARME_WEBHOOK_URL'),
        'webhook_user' => env('PAGARME_WEBHOOK_USER', 'partiumenu'),
        'webhook_secret' => env('PAGARME_WEBHOOK_SECRET'),
        'statement_descriptor' => env('PAGARME_STATEMENT_DESCRIPTOR', 'PARTIUMENU'),
        'connect_url' => env('PAGARME_CONNECT_URL'),
        'timeout' => env('PAGARME_TIMEOUT', 20),
    ],

    'ifood' => [
        'environment' => env('IFOOD_ENVIRONMENT', 'sandbox'),
        'base_url' => env('IFOOD_BASE_URL', 'https://merchant-api.ifood.com.br'),
        'auth_path' => env('IFOOD_AUTH_PATH', '/authentication/v1.0/oauth/token'),
        'centralized_client_id' => env('IFOOD_CENTRALIZED_CLIENT_ID'),
        'centralized_client_secret' => env('IFOOD_CENTRALIZED_CLIENT_SECRET'),
        'distributed_client_id' => env('IFOOD_DISTRIBUTED_CLIENT_ID'),
        'distributed_client_secret' => env('IFOOD_DISTRIBUTED_CLIENT_SECRET'),
        'webhook_url' => env('IFOOD_WEBHOOK_URL'),
        'webhook_path' => '/api/v1/integrations/ifood/webhook',
        'webhook_secret' => env('IFOOD_WEBHOOK_SECRET'),
        'presence_by_merchant' => env('IFOOD_PRESENCE_BY_MERCHANT', false),
        'order_categories' => array_filter(array_map('trim', explode(',', env('IFOOD_ORDER_CATEGORIES', 'FOOD')))),
        'timeout' => env('IFOOD_TIMEOUT', 20),
        'financial_path' => env('IFOOD_FINANCIAL_PATH', '/financial/v3.0'),
        'image_cdn_base' => env(
            'IFOOD_IMAGE_CDN_BASE',
            'https://static-images.ifood.com.br/image/upload/t_medium/pratos'
        ),
    ],

    'gemini' => [
        'enabled' => env('GEMINI_ENABLED', true),
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'timeout' => env('GEMINI_TIMEOUT', 20),
        'insights_cache_ttl' => env('INSIGHTS_CACHE_TTL', 1800),
        'max_output_tokens' => env('GEMINI_MAX_OUTPUT_TOKENS', 4096),
    ],

    'openai' => [
        'enabled' => env('OPENAI_ENABLED', false),
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => env('OPENAI_TIMEOUT', 20),
    ],

    'evolution' => [
        'enabled' => env('EVOLUTION_ENABLED', false),
        'test_mode' => env('WHATSAPP_TEST_MODE', false),
        'base_url' => rtrim((string) env('EVOLUTION_API_URL', ''), '/'),
        'api_key' => env('EVOLUTION_API_KEY'),
        'webhook_url' => env('EVOLUTION_WEBHOOK_URL'),
        'webhook_secret' => env('EVOLUTION_WEBHOOK_SECRET'),
        'timeout' => (int) env('EVOLUTION_TIMEOUT', 50),
        'provision_timeout' => (int) env('EVOLUTION_PROVISION_TIMEOUT', 90),
        'message_timeout' => (int) env('EVOLUTION_MESSAGE_TIMEOUT', 45),
        'default_instance' => env('EVOLUTION_INSTANCE_NAME'),
    ],

    'meta_whatsapp' => [
        'enabled' => env('META_WHATSAPP_ENABLED', false),
        'test_mode' => env('META_WHATSAPP_TEST_MODE', false),
        'app_id' => env('META_WHATSAPP_APP_ID'),
        'app_secret' => env('META_WHATSAPP_APP_SECRET'),
        'embedded_signup_config_id' => env('META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'),
        'webhook_verify_token' => env('META_WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'graph_version' => env('META_WHATSAPP_GRAPH_VERSION', 'v21.0'),
        'timeout' => (int) env('META_WHATSAPP_TIMEOUT', 30),
        'default_pin' => env('META_WHATSAPP_DEFAULT_PIN', '123456'),
        'otp_template_name' => env('META_WHATSAPP_OTP_TEMPLATE_NAME'),
        'otp_template_language' => env('META_WHATSAPP_OTP_TEMPLATE_LANGUAGE', 'pt_BR'),
    ],

];
