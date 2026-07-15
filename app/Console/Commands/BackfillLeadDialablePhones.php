<?php

namespace App\Console\Commands;

use App\Models\WorkflowLead;
use App\Support\LeadDialablePhone;
use Illuminate\Console\Command;

class BackfillLeadDialablePhones extends Command
{
    protected $signature = 'leads:backfill-dialable-phones
                            {--workflow= : Limit to one workflow id}
                            {--assigned-only : Only leads assigned to a setter}
                            {--limit=5000 : Max rows to scan}';

    protected $description = 'Copy dialable phones from enrichment/markdown into lead phone columns so dialer queues work.';

    public function handle(): int
    {
        $query = WorkflowLead::query()
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')));

        if ($this->option('workflow')) {
            $query->where('workflow_id', (int) $this->option('workflow'));
        }

        if ($this->option('assigned-only')) {
            $query->whereNotNull('assigned_user_id');
        }

        $scanned = 0;
        $updated = 0;

        $query->chunkById(200, function ($leads) use (&$scanned, &$updated) {
            foreach ($leads as $lead) {
                $scanned++;
                if (LeadDialablePhone::hasPersistedDialablePhone($lead)) {
                    continue;
                }
                if (LeadDialablePhone::persist($lead)) {
                    $updated++;
                }
            }
        });

        $this->info("Scanned {$scanned} leads, updated {$updated} with dialable phones.");

        return self::SUCCESS;
    }
}
