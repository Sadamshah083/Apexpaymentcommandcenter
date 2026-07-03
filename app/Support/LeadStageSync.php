<?php

namespace App\Support;

use App\Models\WorkflowLead;

class LeadStageSync
{
    public static function forImport(): string
    {
        return 'new_lead';
    }

    public static function forSetterAssignment(): string
    {
        return 'new_lead';
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    public static function mergeStage(WorkflowLead $lead, array $updates = []): array
    {
        $phase = (string) ($updates['pipeline_phase'] ?? $lead->pipeline_phase);
        $setterStatus = (string) ($updates['setter_status'] ?? $lead->setter_status ?? '');
        $closerStatus = (string) ($updates['closer_status'] ?? $lead->closer_status ?? '');

        $stage = match ($phase) {
            'imported', 'enriched' => 'new_lead',
            'with_setter' => match ($setterStatus) {
                'contacted', 'follow_up' => 'connected',
                'appointment_settled' => 'meeting_scheduled',
                'not_interested' => 'follow_up',
                default => 'new_lead',
            },
            'appointment_settled' => 'meeting_scheduled',
            'with_closer' => match ($closerStatus) {
                'follow_up' => 'follow_up',
                'sale_made' => 'closed_won',
                'closed_lost' => 'closed_lost',
                default => 'proposal_sent',
            },
            'closed' => match ($closerStatus) {
                'sale_made' => 'closed_won',
                'closed_lost' => 'closed_lost',
                default => $lead->stage ?: 'proposal_sent',
            },
            default => $lead->stage ?: 'new_lead',
        };

        return array_merge($updates, ['stage' => $stage]);
    }
}
