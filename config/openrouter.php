<?php

return [
    'api_key' => env('OPENROUTER_API_KEY'),
    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),

    // Prefer the auto free router first — specific free models often hit daily RPM caps.
    'model' => env('OPENROUTER_MODEL', 'openrouter/free'),

    // Tried in order if primary returns 404/429
    'fallback_models' => array_filter(array_map('trim', explode(',', env(
        'OPENROUTER_FALLBACK_MODELS',
        'openai/gpt-oss-20b:free,openrouter/free,meta-llama/llama-3.3-70b-instruct'
    )))),

    'site_url' => env('OPENROUTER_SITE_URL', env('APP_URL', 'http://localhost')),
    'site_name' => env('OPENROUTER_SITE_NAME', 'Email Checker'),

    'web_search_enabled' => env('OPENROUTER_WEB_SEARCH_ENABLED', true),
    'duckduckgo_fallback' => env('OPENROUTER_DUCKDUCKGO_FALLBACK', true),

    'timeout' => (int) env('OPENROUTER_TIMEOUT', 120),

    // Fast call-summary path: prefer working free instruct models; paid llama as last resort.
    'call_summary_model' => env('OPENROUTER_CALL_SUMMARY_MODEL', 'openai/gpt-oss-20b:free'),
    'call_summary_fallback_models' => array_values(array_filter(array_map('trim', explode(',', env(
        'OPENROUTER_CALL_SUMMARY_FALLBACK_MODELS',
        'openrouter/free,meta-llama/llama-3.3-70b-instruct'
    ))))),
    'call_summary_timeout' => (int) env('OPENROUTER_CALL_SUMMARY_TIMEOUT', 14),
    'call_summary_max_models' => (int) env('OPENROUTER_CALL_SUMMARY_MAX_MODELS', 2),
    // Protect the app: max AI generations per workspace per minute.
    'call_summary_rate_per_minute' => (int) env('OPENROUTER_CALL_SUMMARY_RATE_PER_MINUTE', 8),
];
