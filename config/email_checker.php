<?php

return [
    'verification' => [
        'chunk_size' => (int) env('EMAIL_CHECKER_CHUNK_SIZE', 50),
        'smtp_enabled' => env('EMAIL_CHECKER_SMTP_ENABLED', false),
        'smtp_timeout' => (int) env('EMAIL_CHECKER_SMTP_TIMEOUT', 10),
        'smtp_from_email' => env('EMAIL_CHECKER_SMTP_FROM', 'verify@example.com'),
        'dns_sleep_ms' => (int) env('EMAIL_CHECKER_DNS_SLEEP_MS', 100),
    ],

    'disposable' => [
        'blocklist_url' => 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/refs/heads/main/disposable_email_blocklist.conf',
    ],

    'content' => [
        'spam_threshold' => 5.0,
        'max_score' => 10.0,
    ],

    'inbound' => [
        'domain' => env('EMAIL_CHECKER_INBOUND_DOMAIN'),
        'imap_host' => env('EMAIL_CHECKER_IMAP_HOST'),
        'imap_port' => (int) env('EMAIL_CHECKER_IMAP_PORT', 993),
        'imap_username' => env('EMAIL_CHECKER_IMAP_USERNAME'),
        'imap_password' => env('EMAIL_CHECKER_IMAP_PASSWORD'),
        'inbox_ttl_hours' => (int) env('EMAIL_CHECKER_INBOX_TTL_HOURS', 24),
    ],
];
