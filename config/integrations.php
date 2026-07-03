<?php

return [

    'morpheus' => [
        'api_key' => env('MORPHEUS_API_KEY'),
        'host' => env('MORPHEUS_HOST', 'apexone.morpheus.cx'),
        'sip_host' => env('MORPHEUS_SIP_HOST'),
        'portal_url' => env('MORPHEUS_PORTAL_URL'),
        'outbound_prefix' => env('MORPHEUS_OUTBOUND_PREFIX', ''),
        'sip_params' => env('MORPHEUS_SIP_PARAMS', 'user=phone'),
        'dial_method' => env('MORPHEUS_DIAL_METHOD', 'auto'),
        'ring_timeout' => (int) env('MORPHEUS_RING_TIMEOUT', 30),
        'extension_password' => env('MORPHEUS_EXTENSION_PASSWORD'),
        'sip_wss_url' => env('MORPHEUS_SIP_WSS_URL'),
        'webrtc_enabled' => (bool) env('MORPHEUS_WEBRTC_ENABLED', true),
        'webphone_auto_answer' => (bool) env('MORPHEUS_WEBPHONE_AUTO_ANSWER', true),
        'stun_servers' => env('MORPHEUS_STUN_SERVERS', 'stun:stun.l.google.com:19302'),
        'platform_api_key' => env('MORPHEUS_PLATFORM_API_KEY'),
        'default_campaign_id' => env('MORPHEUS_DEFAULT_CAMPAIGN_ID'),
    ],

    'communications' => [
        'cache_ttl_minutes' => (int) env('COMMUNICATIONS_CACHE_TTL', 10),
        'default_days' => (int) env('COMMUNICATIONS_DEFAULT_DAYS', 14),
        'list_page_size' => (int) env('COMMUNICATIONS_LIST_PAGE_SIZE', 20),
        'list_max_pages' => 1,
        'detail_max_pages' => 1,
        'user_fallback' => (bool) env('COMMUNICATIONS_USER_FALLBACK', true),
        'user_fallback_on_empty' => (bool) env('COMMUNICATIONS_USER_FALLBACK_ON_EMPTY', true),
        'user_fallback_max_users' => (int) env('COMMUNICATIONS_USER_FALLBACK_MAX_USERS', 25),
        'http_timeout_seconds' => (int) env('COMMUNICATIONS_HTTP_TIMEOUT', 8),
        'default_caller_id' => env('COMMUNICATIONS_DEFAULT_CALLER_ID'),
    ],

];
