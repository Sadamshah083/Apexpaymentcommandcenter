<?php

return [

    'zoom' => [
        'account_id' => env('ZOOM_ACCOUNT_ID'),
        'client_id' => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
        'webhook_secret' => env('ZOOM_WEBHOOK_SECRET'),
        'api_base' => env('ZOOM_API_BASE', 'https://api.zoom.us/v2'),
        'oauth_url' => env('ZOOM_OAUTH_URL', 'https://zoom.us/oauth/token'),
        'required_scopes' => [
            'user:read:list_users:admin' => 'List Zoom users (Contacts tab)',
            'phone:read:list_call_logs:admin' => 'Zoom Phone call history (Contacts timeline, Call logs tab)',
            'cloud_recording:read:list_user_recordings:admin' => 'Required for Recordings tab (meeting recordings per user)',
            'cloud_recording:read:list_account_recordings:master' => 'Optional: account-wide meeting recordings (some accounts require master, not admin)',
            'cloud_recording:read:list_account_recordings:admin' => 'Optional: account-wide meeting recordings (admin variant)',
            'phone:read:list_call_recordings:admin' => 'List phone recordings (required for Play/Download)',
            'phone:read:call_recording:admin' => 'Stream and download phone recording audio',
            'phone:read:list_voicemails:admin' => 'List account voicemails (Voicemails tab)',
            'phone:read:voicemail:admin' => 'Download and play voicemail audio',
            'phone:read:list_sms_sessions:admin' => 'List SMS conversations (SMS tab)',
            'phone:read:sms_session:admin' => 'Read SMS message history in a session',
            'phone_sms:read:admin' => 'Optional: legacy SMS read scope (add if list_sms_sessions fails)',
            'phone:read:list_call_queues:admin' => 'List call queues (Team tab)',
            'phone:read:list_recordings:admin' => 'Optional: per-user phone recordings list',
            'team_chat:read:list_user_channels:admin' => 'List Zoom Team Chat channels (Chat tab)',
            'team_chat:read:list_user_messages:admin' => 'Read Team Chat messages in channels and DMs',
            'team_chat:read:channel:admin' => 'Read Team Chat channel metadata',
            'team_chat:write:admin' => 'Send Team Chat messages (Chat compose)',
            'phone:read:list_users:admin' => 'List Zoom Phone users (Dialer caller ID)',
            'phone:read:sms_message:admin' => 'Send SMS messages (SMS compose; POST /phone/sms/messages)',
        ],
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
