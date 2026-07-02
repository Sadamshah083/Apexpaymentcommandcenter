<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Lead import pipeline enrichment
    |--------------------------------------------------------------------------
    | Fast defaults: fewer web queries, single Gemini pass, no follow-up.
    | Set WORKFLOW_FAST_ENRICHMENT=false for maximum data quality.
    */

    'fast_mode' => filter_var(env('WORKFLOW_FAST_ENRICHMENT', true), FILTER_VALIDATE_BOOLEAN),

    'gemini_model' => env('WORKFLOW_GEMINI_MODEL', 'gemini-2.5-flash'),

    'gemini_fallback_models' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('WORKFLOW_GEMINI_FALLBACK_MODELS', 'gemini-2.5-pro'))
    ))),

    'gemini_max_output_tokens' => (int) env('WORKFLOW_GEMINI_MAX_OUTPUT_TOKENS', 2048),

    'gemini_max_attempts' => (int) env('WORKFLOW_GEMINI_MAX_ATTEMPTS', 1),

    'gemini_thinking_budget' => (int) env('WORKFLOW_GEMINI_THINKING_BUDGET', 0),

    'gemini_google_search_enabled' => filter_var(env('WORKFLOW_GEMINI_GOOGLE_SEARCH', false), FILTER_VALIDATE_BOOLEAN),

    'gemini_timeout' => (int) env('WORKFLOW_GEMINI_TIMEOUT', 60),

    'web_search_queries' => (int) env('WORKFLOW_WEB_SEARCH_QUERIES', 2),

    'web_search_timeout' => (int) env('WORKFLOW_WEB_SEARCH_TIMEOUT', 12),

    'web_search_pause_ms' => (int) env('WORKFLOW_WEB_SEARCH_PAUSE_MS', 100),

    'follow_up_enabled' => filter_var(env('WORKFLOW_FOLLOW_UP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'follow_up_min_score' => (int) env('WORKFLOW_FOLLOW_UP_MIN_SCORE', 3),

    'skip_auto_verification' => filter_var(env('WORKFLOW_SKIP_AUTO_VERIFICATION', true), FILTER_VALIDATE_BOOLEAN),

    'openrouter_max_tokens' => (int) env('WORKFLOW_OPENROUTER_MAX_TOKENS', 2048),

    'openrouter_web_search_enabled' => filter_var(env('WORKFLOW_OPENROUTER_WEB_SEARCH', false), FILTER_VALIDATE_BOOLEAN),

    'health_check_cache_minutes' => (int) env('WORKFLOW_ENRICHMENT_HEALTH_CACHE_MINUTES', 10),
];
