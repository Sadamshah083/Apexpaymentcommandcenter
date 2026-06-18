<?php

return [
    'leads_per_page' => (int) env('PAGINATION_LEADS_PER_PAGE', 20),
    'pipeline_leads_per_page' => (int) env('PAGINATION_PIPELINE_LEADS_PER_PAGE', 25),
    'workflows_per_page' => (int) env('PAGINATION_WORKFLOWS_PER_PAGE', 8),
];
