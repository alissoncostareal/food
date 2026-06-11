<?php

return [
    'customer_app_url' => rtrim((string) env('CUSTOMER_APP_URL', env('APP_URL', 'https://app.partiumenu.com.br')), '/'),

    'human_mode_hours' => (int) env('WHATSAPP_HUMAN_MODE_HOURS', 4),

    'ai_rate_limit_per_hour' => (int) env('WHATSAPP_AI_RATE_LIMIT_PER_HOUR', 20),

    'ai_max_history_messages' => (int) env('WHATSAPP_AI_MAX_HISTORY', 6),

    'session_ttl_hours' => (int) env('WHATSAPP_SESSION_TTL_HOURS', 24),
];
