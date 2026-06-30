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

        $importDone = $workflow->status !== 'mapping' && ($workflow->ingestion_complete || $workflow->total_leads > 0);
        $enrichActive = in_array($workflow->status, ['extracting', 'pending'], true)
            && $workflow->runsEnrichmentOnImport();
        $enrichSkipped = $workflow->isImportOnly() && $importDone && $enriched === 0 && $failed === 0;
        $enrichDone = $enriched > 0 || $failed > 0
            || ($importDone && ! $workflow->runsEnrichmentOnImport() && ! $enrichActive);
        $reviewActive = $pending > 0;
        $reviewDone = $importDone && $pending === 0 && $enriched > 0;
        $distributeDone = $assigned > 0;

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
                'detail' => $assigned.' assigned',
            ],
        ];
    }
}
