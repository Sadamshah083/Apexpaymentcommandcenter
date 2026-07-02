<?php

namespace App\Support;

use App\Models\Workflow;

class PipelineProgress
{
    /**
     * @return array<int, array{key: string, label: string, done: bool, active: bool, detail: string}>
     */
    public static function steps(Workflow $workflow): array
    {
        $pending = (int) ($workflow->pending_verification_count ?? 0);
        $failed = (int) ($workflow->failed_leads ?? 0);
        $enriched = (int) ($workflow->enriched_leads ?? 0);
        $attempted = $enriched + $failed;
        $assigned = (int) ($workflow->assigned_leads_count ?? 0);
        $importedPending = (int) ($workflow->imported_leads_count ?? 0);
        $extractingPending = (int) ($workflow->extracting_leads_count ?? 0);
        $totalLeads = (int) ($workflow->total_leads ?? 0);

        $importDone = $workflow->status !== 'mapping' && ($workflow->ingestion_complete || $totalLeads > 0);
        $enrichActive = $workflow->runsEnrichmentOnImport()
            && ($importedPending > 0 || $extractingPending > 0 || in_array($workflow->status, ['extracting', 'pending'], true));
        $enrichSkipped = $workflow->isImportOnly() && $importDone && $attempted === 0 && ! $enrichActive;
        $enrichDone = $totalLeads > 0
            ? ($attempted >= $totalLeads && ! $enrichActive)
            : ($importDone && ! $workflow->runsEnrichmentOnImport() && ! $enrichActive);
        $reviewActive = $pending > 0;
        $reviewDone = $enrichDone && $pending === 0 && $enriched > 0;
        $remaining = (int) ($workflow->ready_to_distribute_count ?? max(0, $enriched - $assigned));
        $distributeDone = $enrichDone && $remaining === 0 && $assigned > 0;

        return [
            [
                'key' => 'import',
                'label' => 'Import',
                'done' => $importDone,
                'active' => false,
                'detail' => $workflow->total_leads.' leads',
            ],
            [
                'key' => 'enrich',
                'label' => 'Enrich',
                'done' => $workflow->status === 'completed' || ($enrichDone && ! $enrichActive),
                'active' => $enrichActive,
                'detail' => $enrichActive
                    ? $attempted.' / '.$workflow->total_leads
                    : ($enrichSkipped ? 'Skipped — run later' : ($enrichDone ? 'Complete' : 'Waiting')),
            ],
            [
                'key' => 'review',
                'label' => 'Review',
                'done' => $reviewDone,
                'active' => $reviewActive,
                'detail' => $pending > 0 ? $pending.' waiting' : ($reviewDone ? 'Cleared' : '—'),
            ],
            [
                'key' => 'distribute',
                'label' => 'Distribute',
                'done' => $distributeDone && $workflow->status === 'completed',
                'active' => $assigned > 0 && $pending === 0,
                'detail' => $assigned > 0
                    ? $assigned.' / '.$workflow->total_leads.' assigned'
                    : ($enrichDone ? '0 assigned' : '—'),
            ],
        ];
    }
}
