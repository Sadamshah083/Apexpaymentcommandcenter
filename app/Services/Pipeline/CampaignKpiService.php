<?php

namespace App\Services\Pipeline;

use App\Models\CommunicationCallLog;
use App\Models\LeadCampaign;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CampaignKpiService
{
    /**
     * Dial / connect / disposition KPIs for one campaign.
     *
     * @return array{
     *   dials: int,
     *   connected: int,
     *   connect_rate: float,
     *   dispositioned: int,
     *   dispositions: array<int, array{label: string, count: int}>
     * }
     */
    public function forCampaign(Workspace $workspace, int $campaignId): array
    {
        $map = $this->forCampaigns($workspace, [$campaignId]);

        return $map[$campaignId] ?? $this->emptyKpis();
    }

    /**
     * KPI map keyed by campaign id for dashboard / index tables.
     *
     * @param  Collection<int, LeadCampaign>|array<int, int|LeadCampaign>  $campaigns
     * @return array<int, array{dials: int, connected: int, connect_rate: float, dispositioned: int, dispositions: array<int, array{label: string, count: int}>}>
     */
    public function forCampaigns(Workspace $workspace, Collection|array $campaigns): array
    {
        $ids = collect($campaigns)
            ->map(fn ($campaign) => is_numeric($campaign) ? (int) $campaign : (int) ($campaign->id ?? 0))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $leadToCampaign = DB::table('workflow_leads')
            ->whereIn('campaign_id', $ids)
            ->pluck('campaign_id', 'id')
            ->mapWithKeys(fn ($campaignId, $leadId) => [(int) $leadId => (int) $campaignId])
            ->all();

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->emptyKpis();
        }

        if ($leadToCampaign === []) {
            return $out;
        }

        $leadIdSet = array_fill_keys(array_keys($leadToCampaign), true);

        $logs = CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('meta')
            ->whereRaw("JSON_EXTRACT(meta, '$.lead_id') IS NOT NULL")
            ->get(['id', 'disposition', 'duration_sec', 'status', 'meta']);

        $grouped = [];
        foreach ($ids as $id) {
            $grouped[$id] = [];
        }

        foreach ($logs as $log) {
            $leadId = (int) data_get($log->meta, 'lead_id', 0);
            if ($leadId < 1 || ! isset($leadIdSet[$leadId])) {
                continue;
            }
            $campaignId = $leadToCampaign[$leadId] ?? 0;
            if ($campaignId > 0 && isset($grouped[$campaignId])) {
                $grouped[$campaignId][] = $log;
            }
        }

        foreach ($ids as $id) {
            $out[$id] = $this->summarizeLogs(collect($grouped[$id] ?? []));
        }

        return $out;
    }

    /**
     * @param  Collection<int, CommunicationCallLog>  $logs
     * @return array{dials: int, connected: int, connect_rate: float, dispositioned: int, dispositions: array<int, array{label: string, count: int}>}
     */
    protected function summarizeLogs(Collection $logs): array
    {
        $dials = $logs->count();
        $notConnectedLabels = [
            'no answer',
            'no-answer',
            'answering machine',
            'answer machine',
            'voicemail',
            'busy',
            'failed',
            'system error',
            'cancelled',
            'canceled',
        ];

        $connected = $logs->filter(function (CommunicationCallLog $log) use ($notConnectedLabels) {
            $disposition = mb_strtolower(trim((string) ($log->disposition ?? '')));
            $result = strtolower((string) data_get($log->meta, 'call_result', ''));

            if ($disposition !== '') {
                foreach ($notConnectedLabels as $label) {
                    if ($disposition === $label || str_contains($disposition, $label)) {
                        return false;
                    }
                }

                // Any other disposition means an agent reached a live outcome.
                return true;
            }

            if (in_array($result, ['connected', 'answered'], true)) {
                return true;
            }

            // Fallback: meaningful talk time only when no disposition was saved.
            return (int) ($log->duration_sec ?? 0) >= 20;
        })->count();

        $dispositionCounts = [];
        foreach ($logs as $log) {
            $label = trim((string) ($log->disposition ?? ''));
            if ($label === '') {
                continue;
            }
            $key = mb_strtolower($label);
            if (! isset($dispositionCounts[$key])) {
                $dispositionCounts[$key] = ['label' => $label, 'count' => 0];
            }
            $dispositionCounts[$key]['count']++;
        }

        uasort($dispositionCounts, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'dials' => $dials,
            'connected' => $connected,
            'connect_rate' => $dials > 0 ? round(($connected / $dials) * 100, 1) : 0.0,
            'dispositioned' => array_sum(array_column($dispositionCounts, 'count')),
            'dispositions' => array_values($dispositionCounts),
        ];
    }

    /**
     * @return array{dials: int, connected: int, connect_rate: float, dispositioned: int, dispositions: array<int, array{label: string, count: int}>}
     */
    protected function emptyKpis(): array
    {
        return [
            'dials' => 0,
            'connected' => 0,
            'connect_rate' => 0.0,
            'dispositioned' => 0,
            'dispositions' => [],
        ];
    }
}
