<?php

namespace App\Console\Commands;

use App\Models\CrmLead;
use App\Services\Crm\CrmLeadFingerprint;
use Illuminate\Console\Command;

class BackfillCrmLeadFingerprints extends Command
{
    protected $signature = 'crm:backfill-fingerprints {--chunk=500 : Rows per batch}';

    protected $description = 'Populate research_fingerprint on CRM leads that are missing it';

    public function handle(CrmLeadFingerprint $fingerprint): int
    {
        $chunk = (int) $this->option('chunk');
        $updated = 0;

        CrmLead::query()
            ->whereNull('research_fingerprint')
            ->orderBy('id')
            ->chunkById($chunk, function ($leads) use ($fingerprint, &$updated) {
                foreach ($leads as $lead) {
                    $lead->update([
                        'research_fingerprint' => $fingerprint->make(
                            $lead->business_name,
                            $lead->address,
                            $lead->city,
                            $lead->state,
                            $lead->zip_code,
                        ),
                    ]);
                    $updated++;
                }
            });

        $this->info("Updated {$updated} lead(s).");

        return self::SUCCESS;
    }
}
