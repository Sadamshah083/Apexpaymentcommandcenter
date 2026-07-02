<?php

return [

    'morpheus' => [
        'api_key' => env('MORPHEUS_API_KEY'),
        'host' => env('MORPHEUS_HOST', 'apexone.morpheus.cx'),
        'sip_host' => env('MORPHEUS_SIP_HOST'),
        'portal_url' => env('MORPHEUS_PORTAL_URL'),
        'outbound_prefix' => env('MORPHEUS_OUTBOUND_PREFIX', ''),
        'sip_params' => env('MORPHEUS_SIP_PARAMS', 'user=phone'),
        'dial_method' => env('MORPHEUS_DIAL_METHOD', 'sip'),
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
