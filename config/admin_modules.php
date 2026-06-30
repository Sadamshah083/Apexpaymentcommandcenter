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
        'lead_pipeline' => [
            'label' => 'Lead Pipeline',
            'description' => 'Import leads, workflows, and pipeline overview',
            'section' => 'Lead Pipeline',
            'default_route' => 'admin.workflows.index',
            'routes' => [
                'admin.workflows.*',
                'admin.leads.*',
            ],
        ],
        'lead_tags' => [
            'label' => 'Lead Tags',
            'description' => 'Tag leads and run batch enrich or distribute actions',
            'section' => 'Lead Pipeline',
            'default_route' => 'admin.lead-tags.index',
            'routes' => [
                'admin.lead-tags.*',
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
        'crm' => [
            'label' => 'CRM Campaigns',
            'description' => 'CRM-style campaign imports and lead management',
            'section' => 'CRM',
            'default_route' => 'admin.crm.index',
            'routes' => [
                'admin.crm.*',
            ],
        ],
        'business_research' => [
            'label' => 'Business Research',
            'description' => 'AI-assisted company and contact research',
            'section' => 'Research',
            'default_route' => 'admin.business-research.index',
            'routes' => [
                'admin.business-research.*',
            ],
        ],
        'communications' => [
            'label' => 'Communications Hub',
            'description' => 'Calls, SMS, Zoom logs, and contact timelines',
            'section' => 'Communications',
            'default_route' => 'admin.communications.index',
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
