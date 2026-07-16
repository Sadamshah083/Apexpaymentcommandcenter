<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deployment notice (admin + agent portals)
    |--------------------------------------------------------------------------
    |
    | When enabled, authenticated users see a one-time (per version) modal asking
    | them to wait patiently while new features ship.
    |
    */
    'notice_enabled' => (bool) env('DEPLOYMENT_NOTICE_ENABLED', true),

    'notice_title' => env('DEPLOYMENT_NOTICE_TITLE', 'Deploying new features'),

    'notice_message' => env(
        'DEPLOYMENT_NOTICE_MESSAGE',
        'Please be patient — we are deploying a new feature in ApexOne Payment Command Center. Thanks for your patience.'
    ),

    /** Bump this to show the notice again after users dismissed a prior version. */
    'notice_version' => env('DEPLOYMENT_NOTICE_VERSION', '2026-07-16-a'),

];
