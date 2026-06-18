<?php

return [
    // gemini | openrouter — when gemini, OpenRouter is never used
    'provider' => env('BUSINESS_RESEARCH_PROVIDER', 'gemini'),

    // Pre-fetch web snippets from DuckDuckGo before calling Gemini (supplements Google Search grounding)
    'web_context_first' => env('BUSINESS_RESEARCH_WEB_CONTEXT_FIRST', true),

    // Max DuckDuckGo queries to run per research job
    'max_search_queries' => (int) env('BUSINESS_RESEARCH_MAX_SEARCH_QUERIES', 14),

    // Second Gemini pass when owner/processor/phone still missing
    'follow_up_enabled' => env('BUSINESS_RESEARCH_FOLLOW_UP', true),

    // Job timeout (seconds) — multi-search can take 2–4 minutes
    'timeout' => (int) env('BUSINESS_RESEARCH_TIMEOUT', 300),
];
