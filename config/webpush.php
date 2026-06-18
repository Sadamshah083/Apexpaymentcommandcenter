<?php

return [
    'subject' => env('WEBPUSH_SUBJECT', env('MAIL_FROM_ADDRESS', 'mailto:admin@example.com')),

    'public_key' => env('WEBPUSH_PUBLIC_KEY'),

    'private_key' => env('WEBPUSH_PRIVATE_KEY'),
];
