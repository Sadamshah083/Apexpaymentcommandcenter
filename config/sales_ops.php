<?php

return [
    'leads_per_setter' => (int) env('SALES_OPS_LEADS_PER_SETTER', 500),

    'roles' => [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'manager' => 'Manager',
        'appointment_setter_team_lead' => 'Appointment Setter Team Lead',
        'appointment_setter' => 'Appointment Setter',
        'closers_team_lead' => 'Closers Team Lead',
        'closer' => 'Closer',
    ],

    'portal_roles' => [
        'appointment_setter_team_lead',
        'appointment_setter',
        'closers_team_lead',
        'closer',
    ],

    'admin_portal_roles' => [
        'super_admin',
        'admin',
        'manager',
    ],

    'pipeline_phases' => [
        'imported' => 'Imported',
        'enriched' => 'Enriched',
        'enriching' => 'Enriching',
        'with_setter' => 'With Appointment Setter',
        'appointment_settled' => 'Appointment Settled',
        'with_closer' => 'With Closer',
        'closed' => 'Closed',
    ],

    'setter_statuses' => [
        'new' => 'New',
        'contacted' => 'Contacted',
        'follow_up' => 'Follow Up',
        'appointment_settled' => 'Appointment Settled',
        'not_interested' => 'Not Interested',
    ],

    'closer_statuses' => [
        'new' => 'New',
        'proposal_sent' => 'Proposal Sent',
        'follow_up' => 'Follow Up',
        'sale_made' => 'Sale Made',
        'closed_lost' => 'Closed Lost',
    ],

    'activity_types' => [
        'note' => 'Note',
        'dial' => 'Outbound Dial',
        'email' => 'Follow-up Email',
        'sms' => 'Follow-up SMS',
        'setter_status_change' => 'Setter status change',
        'closer_status_change' => 'Closer status change',
    ],
];
