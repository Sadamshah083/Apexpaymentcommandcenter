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
        $enriched = (int) ($workflow->processed_leads ?? 0) + $pending;
        $assigned = (int) ($workflow->assigned_leads_count ?? 0);

        $importDone = $workflow->status !== 'mapping';
        $enrichActive = in_array($workflow->status, ['extracting', 'pending', 'paused'], true);
        $enrichDone = in_array($workflow->status, ['completed', 'paused'], true) || $enriched > 0;
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
                    ? $enriched.' / '.$workflow->total_leads
                    : ($enrichDone ? 'Complete' : 'Waiting'),
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
