<?php

return [
    'api_key' => env('OPENROUTER_API_KEY'),
    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),

    // Primary free model — google/gemini-2.0-flash-exp:free was removed from OpenRouter
    'model' => env('OPENROUTER_MODEL', 'openai/gpt-oss-20b:free'),

    // Tried in order if primary returns 404/429
    'fallback_models' => array_filter(array_map('trim', explode(',', env('OPENROUTER_FALLBACK_MODELS', 'openrouter/free,meta-llama/llama-3.3-70b-instruct:free')))),

    'site_url' => env('OPENROUTER_SITE_URL', env('APP_URL', 'http://localhost')),
    'site_name' => env('OPENROUTER_SITE_NAME', 'Email Checker'),

    'web_search_enabled' => env('OPENROUTER_WEB_SEARCH_ENABLED', true),
    'duckduckgo_fallback' => env('OPENROUTER_DUCKDUCKGO_FALLBACK', true),

    'timeout' => (int) env('OPENROUTER_TIMEOUT', 120),
];
