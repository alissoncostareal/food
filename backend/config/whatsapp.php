<?php

return [
    'ai_provider' => strtolower((string) env('WHATSAPP_AI_PROVIDER', 'gemini')),

    'human_mode_hours' => (int) env('WHATSAPP_HUMAN_MODE_HOURS', 4),

    'ai_rate_limit_per_hour' => (int) env('WHATSAPP_AI_RATE_LIMIT_PER_HOUR', 20),

    'ai_max_history_messages' => (int) env('WHATSAPP_AI_MAX_HISTORY', 6),

    'ai_faq_min_chars' => (int) env('WHATSAPP_AI_FAQ_MIN_CHARS', 20),

    'session_ttl_hours' => (int) env('WHATSAPP_SESSION_TTL_HOURS', 24),
];
