<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Lead import pipeline enrichment
    |--------------------------------------------------------------------------
    | Speed-first defaults for bulk imports: Gemini Google Search only (no DDG
    | prefetch), no follow-up pass. Raise WORKFLOW_WEB_SEARCH_QUERIES / enable
    | follow-up when you need maximum research depth.
    */

    'gemini_model' => env('WORKFLOW_GEMINI_MODEL', 'gemini-2.5-flash'),

    'gemini_fallback_models' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('WORKFLOW_GEMINI_FALLBACK_MODELS', 'gemini-2.5-pro'))
    ))),

    'gemini_max_output_tokens' => (int) env('WORKFLOW_GEMINI_MAX_OUTPUT_TOKENS', 4096),

    'gemini_thinking_budget' => (int) env('WORKFLOW_GEMINI_THINKING_BUDGET', 0),

    'gemini_google_search_enabled' => filter_var(env('WORKFLOW_GEMINI_GOOGLE_SEARCH', true), FILTER_VALIDATE_BOOLEAN),

    'gemini_timeout' => (int) env('WORKFLOW_GEMINI_TIMEOUT', 90),

    'web_search_queries' => (int) env('WORKFLOW_WEB_SEARCH_QUERIES', 0),

    'follow_up_enabled' => filter_var(env('WORKFLOW_FOLLOW_UP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'follow_up_min_score' => (int) env('WORKFLOW_FOLLOW_UP_MIN_SCORE', 3),

    'openrouter_max_tokens' => (int) env('WORKFLOW_OPENROUTER_MAX_TOKENS', 4096),

    'openrouter_web_search_enabled' => filter_var(env('WORKFLOW_OPENROUTER_WEB_SEARCH', true), FILTER_VALIDATE_BOOLEAN),

    // Keep under OpenRouter free RPM / daily caps while Gemini is depleted.
    'openrouter_fallback_rpm' => (int) env('WORKFLOW_OPENROUTER_FALLBACK_RPM', 8),

    // Seconds to wait before retrying a throttled enrichment job.
    'openrouter_retry_delay_seconds' => (int) env('WORKFLOW_OPENROUTER_RETRY_DELAY', 12),

    // When Gemini/OpenRouter quota is exhausted, promote imported spreadsheet fields so assign can proceed.
    'sheet_fallback_enabled' => filter_var(env('WORKFLOW_SHEET_FALLBACK', true), FILTER_VALIDATE_BOOLEAN),

    'health_check_cache_minutes' => (int) env('WORKFLOW_ENRICHMENT_HEALTH_CACHE_MINUTES', 10),
];
