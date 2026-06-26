<?php

return [
    'leads_per_sdr' => (int) env('SALES_OPS_LEADS_PER_SDR', 500),

    'roles' => [
        'admin' => 'Workspace Admin',
        'data_acquisition' => 'Data Acquisition',
        'sdr' => 'SDR',
        'marketer' => 'SDR', // legacy alias
        'account_executive' => 'Account Executive',
    ],

    'crm_stages' => [
        'new_lead' => 'New Lead',
        'attempted_contact' => 'Attempted Contact',
        'connected' => 'Connected',
        'discovery_completed' => 'Discovery Completed',
        'meeting_scheduled' => 'Meeting Scheduled',
        'proposal_sent' => 'Proposal Sent',
        'follow_up' => 'Follow-Up',
        'closed_won' => 'Closed Won',
        'closed_lost' => 'Closed Lost',
    ],

    'lead_tiers' => [
        'tier_1' => ['label' => 'Tier 1 – New Leads', 'description' => 'No contact attempts made'],
        'tier_2' => ['label' => 'Tier 2 – Active Prospecting', 'description' => '1–3 contact attempts'],
        'tier_3' => ['label' => 'Tier 3 – Follow-Up Prospects', 'description' => '4–10 contact attempts'],
        'tier_4' => ['label' => 'Tier 4 – Long-Term Nurture', 'description' => 'Interested but not ready to switch'],
    ],

    'daily_quotas' => [
        'dials' => 150,
        'conversations' => 15,
        'decision_maker_contacts' => 5,
        'discoveries' => 3,
    ],

    'weekly_quotas' => [
        'discoveries' => 15,
        'qualified_meetings' => 2,
    ],

    'activity_types' => [
        'dial' => 'Outbound Dial',
        'conversation' => 'Live Conversation',
        'decision_maker' => 'Decision-Maker Contact',
        'discovery' => 'Discovery Completed',
        'email' => 'Follow-up Email',
        'sms' => 'Follow-up SMS',
        'linkedin' => 'LinkedIn Connection',
        'meeting_booked' => 'Meeting Booked',
        'note' => 'Note',
    ],

    'pain_points' => [
        'high_fees' => 'High processing fees',
        'poor_service' => 'Poor customer service',
        'funding_delays' => 'Funding delays',
        'equipment_limits' => 'Equipment limitations',
        'chargebacks' => 'Chargeback concerns',
        'hidden_fees' => 'Hidden fees',
        'pci_charges' => 'PCI compliance charges',
    ],

    'offer_types' => [
        'cost_reduction' => 'Cost Reduction / Dual Pricing',
        'equipment_upgrade' => 'Equipment Upgrade',
        'contract_buyout' => 'Contract Buyout',
        'faster_funding' => 'Faster Funding',
        'pos_enhancement' => 'POS Enhancement',
        'statement_review' => 'Statement Review / Savings Audit',
    ],

    'reactivation_sources' => [
        'old_lead' => 'Old Lead',
        'previously_interested' => 'Previously Interested',
        'no_show' => 'No-Show Appointment',
        'lost_opportunity' => 'Lost Opportunity',
        'expired_proposal' => 'Expired Proposal',
    ],
];
