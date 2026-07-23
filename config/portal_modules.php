<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Portal modules (team leads & agents)
    |--------------------------------------------------------------------------
    |
    | null module_permissions on workspace_user = full portal access for role.
    | An explicit array limits the user to only the listed module keys.
    |
    */
    'modules' => [
        'setter_team' => [
            'label' => 'Setter Team',
            'description' => 'Team dashboard, metrics, and setter workload overview',
            'section' => 'Team Lead',
            'roles' => ['appointment_setter_team_lead'],
            'default_route' => 'portal.setter-team.dashboard',
            'routes' => [
                'portal.setter-team.*',
            ],
        ],
        'assign_leads' => [
            'label' => 'Assign Leads',
            'description' => 'Assign enriched leads to setters on your team',
            'section' => 'Team Lead',
            'roles' => ['appointment_setter_team_lead'],
            'routes' => [
                'portal.setter-team.assign-leads',
            ],
        ],
        'closer_team' => [
            'label' => 'Closer Team',
            'description' => 'Team dashboard and closer performance metrics',
            'section' => 'Team Lead',
            'roles' => ['closers_team_lead'],
            'default_route' => 'portal.closer-team.dashboard',
            'routes' => [
                'portal.closer-team.dashboard',
                'portal.closer-team.index',
            ],
        ],
        'closer_queue' => [
            'label' => 'Closer Queue',
            'description' => 'Settled appointments waiting for closer assignment',
            'section' => 'Team Lead',
            'roles' => ['closers_team_lead'],
            'routes' => [
                'portal.closer-team.queue',
            ],
        ],
        'setter_leads' => [
            'label' => 'My Leads',
            'description' => 'Personal lead queue and dial list',
            'section' => 'Agent',
            'roles' => ['appointment_setter'],
            'default_route' => 'portal.setter.dashboard',
            'routes' => [
                'portal.setter.*',
            ],
        ],
        'performance' => [
            'label' => 'Performance',
            'description' => 'Personal quotas and activity metrics',
            'section' => 'Agent',
            'roles' => ['appointment_setter'],
            'routes' => [
                'portal.performance',
            ],
        ],
        'closer_leads' => [
            'label' => 'My Closer Leads',
            'description' => 'Assigned closer leads and deal pipeline',
            'section' => 'Agent',
            'roles' => ['closer'],
            'default_route' => 'portal.closer.dashboard',
            'routes' => [
                'portal.closer.dashboard',
                'portal.closer.index',
            ],
        ],
        'closer_pipeline' => [
            'label' => 'Pipeline',
            'description' => 'Pipeline overview and status tracking',
            'section' => 'Agent',
            'roles' => ['closer'],
            'routes' => [
                'portal.pipeline',
            ],
        ],
        'lead_details' => [
            'label' => 'Lead Details',
            'description' => 'Open lead detail pages, timeline, and activity history',
            'section' => 'Pipeline',
            'roles' => [
                'appointment_setter_team_lead',
                'closers_team_lead',
                'appointment_setter',
                'closer',
            ],
            'always_available' => true,
            'routes' => [
                'portal.leads.*',
            ],
        ],
        'communications' => [
            'label' => 'Communications Hub',
            'description' => 'Calls, dialer, recordings, and team messaging',
            'section' => 'Communications',
            'roles' => [
                'appointment_setter_team_lead',
                'closers_team_lead',
                'appointment_setter',
                'closer',
            ],
            'always_available' => true,
            'routes' => [
                'portal.communications.*',
            ],
        ],
        'call_notes' => [
            'label' => 'Call Notes',
            'description' => 'Review agent call notes and dispositions',
            'section' => 'QA',
            'roles' => ['closers_qa'],
            'always_available' => true,
            'default_route' => 'portal.communications.notes',
            'routes' => [
                'portal.communications.notes',
                'portal.communications.notes.download',
            ],
        ],
        'call_monitoring' => [
            'label' => 'Call Monitoring',
            'description' => 'Live wallboard for in-call, ringing, and idle agents',
            'section' => 'QA',
            'roles' => ['closers_qa'],
            'always_available' => true,
            'default_route' => 'portal.communications.monitoring',
            'routes' => [
                'portal.communications.monitoring',
                'portal.communications.monitoring.*',
            ],
        ],
    ],
];
