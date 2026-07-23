<?php

return [
    'leads_per_page' => (int) env('PAGINATION_LEADS_PER_PAGE', 50),
    'pipeline_leads_per_page' => (int) env('PAGINATION_PIPELINE_LEADS_PER_PAGE', 50),
    'workflows_per_page' => (int) env('PAGINATION_WORKFLOWS_PER_PAGE', 50),
    'members_per_page' => (int) env('PAGINATION_MEMBERS_PER_PAGE', 50),
    'agent_status_logs_per_page' => (int) env('PAGINATION_AGENT_STATUS_LOGS_PER_PAGE', 50),
    'agent_status_cache_ttl' => (int) env('AGENT_STATUS_CACHE_TTL', 60),
];
