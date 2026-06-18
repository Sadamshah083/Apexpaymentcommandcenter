<?php

return [
    'api_key' => env('GEMINI_API_KEY'),

    // Primary model — gemini-2.5-pro for deep multi-source web research (Google AI mode quality)
    'model' => env('GEMINI_MODEL', 'gemini-2.5-pro'),

    'fallback_models' => array_filter(array_map('trim', explode(',', env('GEMINI_FALLBACK_MODELS', 'gemini-2.5-flash,gemini-2.0-flash')))),

    'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),

    // Google Search grounding (like Google AI mode) — billed per prompt on 2.x models
    'google_search_enabled' => env('GEMINI_GOOGLE_SEARCH_ENABLED', true),

    'timeout' => (int) env('GEMINI_TIMEOUT', 240),

    // Thinking budget for 2.5-pro (improves multi-step research reasoning; 0 = disabled)
    'thinking_budget' => (int) env('GEMINI_THINKING_BUDGET', 4096),

    'project_number' => env('GEMINI_PROJECT_NUMBER'),
];
