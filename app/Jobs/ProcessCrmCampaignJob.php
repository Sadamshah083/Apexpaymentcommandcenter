<?php

namespace App\Jobs;

use App\Models\CrmCampaign;
use App\Services\Crm\CrmCsvImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessCrmCampaignJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public int $campaignId,
        public string $filePath,
    ) {}

    public function handle(CrmCsvImporter $importer): void
    {
        set_time_limit(max((int) ini_get('max_execution_time'), 600));

        $campaign = CrmCampaign::findOrFail($this->campaignId);
        $fullPath = Storage::disk('local')->path($this->filePath);

        try {
            $campaign->update(['status' => 'importing', 'started_at' => now()]);

            $result = $importer->import($campaign, $fullPath);

            if ($result['imported'] === 0 && $result['updated'] === 0) {
                $campaign->update([
                    'status' => 'failed',
                    'import_error' => 'No valid rows found. Ensure CSV has a business/company name column.',
                    'completed_at' => now(),
                ]);

                return;
            }

            $campaign->refreshCounts();

            if ($result['needs_research'] === []) {
                $campaign->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            } else {
                $campaign->update(['status' => 'processing']);
            }

            $delayMs = config('crm.dispatch_delay_ms', 100);
            foreach ($result['needs_research'] as $index => $leadId) {
                $dispatch = RunCrmLeadResearchJob::dispatch($leadId);
                if ($delayMs > 0 && $index > 0) {
                    $dispatch->delay(now()->addMilliseconds($index * $delayMs));
                }
            }
            Log::info('CRM campaign imported', [
                'campaign_id' => $campaign->id,
                'new' => $result['imported'],
                'updated' => $result['updated'],
                'cached' => $result['cached'],
                'queued_research' => count($result['needs_research']),
            ]);
        } catch (\Throwable $e) {
            $campaign->update([
                'status' => 'failed',
                'import_error' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        } finally {
            Storage::disk('local')->delete($this->filePath);
        }
    }
}
