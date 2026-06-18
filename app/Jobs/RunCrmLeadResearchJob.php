<?php

namespace App\Jobs;

use App\Models\CrmLead;
use App\Services\BusinessResearch\BusinessResearchService;
use App\Services\Crm\CrmLeadFingerprint;
use App\Services\Crm\CrmResearchCopier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunCrmLeadResearchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public int $leadId,
    ) {}

    public function handle(
        BusinessResearchService $service,
        CrmResearchCopier $copier,
        CrmLeadFingerprint $fingerprint,
    ): void {
        $lead = CrmLead::find($this->leadId);

        if (! $lead) {
            return;
        }

        if ($lead->status === 'processing') {
            if ($lead->updated_at && $lead->updated_at->gt(now()->subMinutes(15))) {
                return;
            }
            $lead->update(['status' => 'pending']);
            $lead->refresh();
        }

        if ($lead->status !== 'pending') {
            return;
        }

        if (! $lead->research_fingerprint) {
            $lead->update([
                'research_fingerprint' => $fingerprint->make(
                    $lead->business_name,
                    $lead->address,
                    $lead->city,
                    $lead->state,
                    $lead->zip_code,
                ),
            ]);
            $lead->refresh();
        }

        if (config('crm.reuse_research', true)) {
            $source = $copier->findCachedSource($lead->research_fingerprint, $lead->id);
            if ($source) {
                $copier->copyToLead($lead, $source);
                $lead->campaign?->refreshCountsThrottled();
                Log::info('CRM lead research copied from cache', ['lead_id' => $lead->id]);

                return;
            }
        }

        try {
            $service->enrichLead($lead);
        } catch (\Throwable $e) {
            Log::warning('CRM lead research failed', [
                'lead_id' => $this->leadId,
                'error' => $e->getMessage(),
            ]);

            $lead->refresh();
            if ($lead->status === 'processing') {
                $lead->update([
                    'status' => 'failed',
                    'error_message' => mb_substr($e->getMessage(), 0, 2000),
                    'researched_at' => now(),
                ]);
                $lead->campaign?->refreshCountsThrottled();
            }
        }
    }
}
