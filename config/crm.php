<?php



return [

    'max_upload_kb' => (int) env('CRM_MAX_UPLOAD_KB', 51200),

    'import_batch_size' => (int) env('CRM_IMPORT_BATCH_SIZE', 100),



    // Re-use completed research from other leads/campaigns with same business fingerprint

    'reuse_research' => env('CRM_REUSE_RESEARCH', true),



    // Skip heavy DuckDuckGo pre-fetch — rely on Gemini + Google Search (faster for bulk)

    'web_context_first' => env('CRM_WEB_CONTEXT_FIRST', false),



    'max_ddg_queries' => (int) env('CRM_MAX_DDG_QUERIES', 3),



    // Re-research completed leads only when CSV input fields changed

    're_research_on_input_change' => env('CRM_RE_RESEARCH_ON_CHANGE', true),



    // Delay between queue job dispatches (ms) — 0 for max throughput

    'dispatch_delay_ms' => (int) env('CRM_DISPATCH_DELAY_MS', 0),



    // Faster bulk AI settings (single Business Intel lookup still uses GEMINI_MODEL pro)

    'gemini_model' => env('CRM_GEMINI_MODEL', 'gemini-2.5-flash'),

    'gemini_fallback_models' => array_filter(array_map('trim', explode(',', env(

        'CRM_GEMINI_FALLBACK_MODELS',

        'gemini-2.0-flash'

    )))),

    'gemini_thinking_budget' => (int) env('CRM_GEMINI_THINKING_BUDGET', 0),

    'gemini_timeout' => (int) env('CRM_GEMINI_TIMEOUT', 90),

    'max_output_tokens' => (int) env('CRM_MAX_OUTPUT_TOKENS', 8192),

    'follow_up_enabled' => env('CRM_FOLLOW_UP', false),



    // Throttle campaign progress DB updates during bulk (seconds)

    'refresh_counts_interval' => (int) env('CRM_REFRESH_COUNTS_INTERVAL', 5),

];


