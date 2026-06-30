<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Lead import pipeline enrichment (cheap defaults)
    |--------------------------------------------------------------------------
    | Separate from GEMINI_* / CRM_* so Business Research can stay on pro
    | while bulk lead enrichment uses flash models and capped output.
    */

    'gemini_model' => env('WORKFLOW_GEMINI_MODEL', 'gemini-2.5-flash'),

    'gemini_fallback_models' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('WORKFLOW_GEMINI_FALLBACK_MODELS', 'gemini-2.5-pro'))
    ))),

    'gemini_max_output_tokens' => (int) env('WORKFLOW_GEMINI_MAX_OUTPUT_TOKENS', 2048),

    'gemini_thinking_budget' => (int) env('WORKFLOW_GEMINI_THINKING_BUDGET', 0),

    'gemini_google_search_enabled' => filter_var(env('WORKFLOW_GEMINI_GOOGLE_SEARCH', false), FILTER_VALIDATE_BOOLEAN),

    'gemini_timeout' => (int) env('WORKFLOW_GEMINI_TIMEOUT', 120),

    'web_search_queries' => (int) env('WORKFLOW_WEB_SEARCH_QUERIES', 2),

    'openrouter_max_tokens' => (int) env('WORKFLOW_OPENROUTER_MAX_TOKENS', 2048),

    'openrouter_web_search_enabled' => filter_var(env('WORKFLOW_OPENROUTER_WEB_SEARCH', false), FILTER_VALIDATE_BOOLEAN),

    'health_check_cache_minutes' => (int) env('WORKFLOW_ENRICHMENT_HEALTH_CACHE_MINUTES', 10),
];
