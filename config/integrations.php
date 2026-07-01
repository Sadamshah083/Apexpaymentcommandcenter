<?php

return [

    'morpheus' => [
        'api_key' => env('MORPHEUS_API_KEY', 'ck_caea4aeef50fcd3d50131c65eb5a13d79e05fcf1aea27305'),
        'host' => env('MORPHEUS_HOST', 'apexone.morpheus.cx'),
    ],

    'communications' => [
        'cache_ttl_minutes' => (int) env('COMMUNICATIONS_CACHE_TTL', 10),
        'default_days' => (int) env('COMMUNICATIONS_DEFAULT_DAYS', 14),
        'list_max_pages' => 1,
        'detail_max_pages' => 3,
        'user_fallback' => (bool) env('COMMUNICATIONS_USER_FALLBACK', true),
        'user_fallback_on_empty' => (bool) env('COMMUNICATIONS_USER_FALLBACK_ON_EMPTY', true),
        'user_fallback_max_users' => (int) env('COMMUNICATIONS_USER_FALLBACK_MAX_USERS', 25),
        'http_timeout_seconds' => (int) env('COMMUNICATIONS_HTTP_TIMEOUT', 12),
        'default_caller_id' => env('COMMUNICATIONS_DEFAULT_CALLER_ID'),
    ],

];
