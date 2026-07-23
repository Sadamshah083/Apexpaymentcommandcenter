<?php

return [
  /*
    |--------------------------------------------------------------------------
    | Admin portal modules
    |--------------------------------------------------------------------------
    |
    | null module_permissions on workspace_user = full access (legacy default).
    | An explicit array limits the user to only the listed module keys.
    |
    */
    'modules' => [
        'dashboard' => [
            'label' => 'Dashboard',
            'description' => 'Workspace overview, pipeline metrics, and performance',
            'section' => 'Overview',
            'default_route' => 'admin.dashboard',
            'always_available' => true,
            'routes' => [
                'admin.dashboard',
                'admin.dashboard.*',
            ],
        ],
        'lead_pipeline' => [
            'label' => 'Lead Pipeline',
            'description' => 'Import leads, workflows, and pipeline overview',
            'section' => 'Lead Pipeline',
            'default_route' => 'admin.dashboard',
            'always_available' => true,
            'routes' => [
                'admin.workflows.*',
                'admin.assigned-leads',
                'admin.leads.*',
                'admin.dashboard',
            ],
        ],
        'maps_scraper' => [
            'label' => 'Maps Lead Scraper',
            'description' => 'Scrape Google Maps small businesses and export Excel by area code',
            'section' => 'Lead Pipeline',
            'default_route' => 'admin.maps-scraper.index',
            'always_available' => true,
            'routes' => [
                'admin.maps-scraper',
                'admin.maps-scraper.*',
            ],
        ],
        'campaigns' => [
            'label' => 'Campaigns',
            'description' => 'Manage lead campaigns and batch operations',
            'section' => 'Lead Pipeline',
            'default_route' => 'admin.campaigns.index',
            'always_available' => true,
            'routes' => [
                'admin.campaigns.*',
            ],
        ],
        'email_lists' => [
            'label' => 'Bulk Email Verifier',
            'description' => 'Upload and verify email lists',
            'section' => 'Email Toolkit',
            'default_route' => 'admin.lists.index',
            'routes' => [
                'admin.lists.*',
            ],
        ],
        'deliverability' => [
            'label' => 'Domain Deliverability Scan',
            'description' => 'Scan domains and inboxes for deliverability',
            'section' => 'Email Toolkit',
            'default_route' => 'admin.deliverability.index',
            'routes' => [
                'admin.deliverability.*',
            ],
        ],
        'content_analyzer' => [
            'label' => 'Outbound Spam Analyzer',
            'description' => 'Analyze outbound email content for spam signals',
            'section' => 'Email Toolkit',
            'default_route' => 'admin.content.index',
            'routes' => [
                'admin.content.*',
            ],
        ],
        'reputation' => [
            'label' => 'Sender Reputation Center',
            'description' => 'Warmup planning, compliance checks, and reputation logs',
            'section' => 'Email Toolkit',
            'default_route' => 'admin.reputation.index',
            'routes' => [
                'admin.reputation.*',
            ],
        ],
        'sales_ops' => [
            'label' => 'Sales Operations',
            'description' => 'Performance, distribution, and reactivation tools',
            'section' => 'Sales Operations',
            'default_route' => 'admin.sales-ops.index',
            'routes' => [
                'admin.sales-ops.*',
                'admin.leads.activities.store',
            ],
        ],
        'communications' => [
            'label' => 'Communications Hub',
            'description' => 'Calls, dialer, recordings, voicemails, and Morpheus telephony',
            'section' => 'Communications',
            'default_route' => 'admin.communications.index',
            'default_route_params' => [
                'channel' => 'inbox',
                'panel' => 'dialer',
            ],
            'always_available' => true,
            'routes' => [
                'admin.communications.*',
            ],
        ],
        'user_management' => [
            'label' => 'User Management',
            'description' => 'Manage collaborators, roles, and module access',
            'section' => 'Workspace Admin',
            'default_route' => 'admin.workspaces.index',
            'routes' => [
                'admin.workspaces.*',
            ],
            'grantable_by' => ['super_admin'],
        ],
    ],
];
