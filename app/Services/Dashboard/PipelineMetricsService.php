<?php

namespace App\Services\Dashboard;

use App\Models\LeadActivity;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

class PipelineMetricsService
{
    /**
     * @return list<string>
     */
    public function metricKeys(): array
    {
        return [
            'total_leads',
            'new',
            'qualified',
            'booked',
            'showed',
            'closed_won',
            'not_now',
            'dead',
        ];
    }

    public function workspaceQuery(Workspace $workspace): Builder
    {
        $workflowIds = $workspace->workflows()->pluck('id')->all();

        return WorkflowLead::query()->whereIn('workflow_id', $workflowIds);
    }

    public function applyMetric(Builder $query, string $metric): Builder
    {
        return match ($metric) {
            'total_leads' => $query,
            'new' => $query->where(function (Builder $q) {
                $q->whereIn('pipeline_phase', ['imported', 'enriched'])
                    ->orWhere(function (Builder $inner) {
                        $inner->where('pipeline_phase', 'with_setter')
                            ->where(function (Builder $status) {
                                $status->where('setter_status', 'new')
                                    ->orWhereNull('setter_status');
                            });
                    });
            }),
            'qualified' => $query->where(function (Builder $q) {
                $q->where('meeting_qualified', true)
                    ->orWhereIn('setter_status', ['contacted', 'follow_up', 'appointment_settled'])
                    ->orWhereIn('stage', ['connected', 'discovery_completed']);
            }),
            'booked' => $query->where(function (Builder $q) {
                $q->whereNotNull('appointment_settled_at')
                    ->orWhereIn('pipeline_phase', ['appointment_settled', 'with_closer', 'closed']);
            }),
            'showed' => $query->where(function (Builder $q) {
                $q->where('pipeline_phase', 'with_closer')
                    ->orWhere(function (Builder $inner) {
                        $inner->where('pipeline_phase', 'closed')
                            ->where('closer_status', 'sale_made');
                    });
            }),
            'closed_won' => $this->scopeClosedWon($query),
            'not_now' => $query->where(function (Builder $q) {
                $q->where('setter_status', 'not_interested')
                    ->orWhere(function (Builder $inner) {
                        $inner->where('closer_status', 'follow_up')
                            ->where('pipeline_phase', '!=', 'closed');
                    });
            }),
            'dead' => $this->scopeClosedLost($query),
            default => $query,
        };
    }

    public function scopeActivePipeline(Builder $query): Builder
    {
        return $query->whereIn('pipeline_phase', [
            'with_setter',
            'appointment_settled',
            'with_closer',
        ]);
    }

    public function scopeClosedWon(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where(function (Builder $inner) {
                $inner->where('pipeline_phase', 'closed')
                    ->where('closer_status', 'sale_made');
            })->orWhere('stage', 'closed_won');
        });
    }

    public function scopeClosedLost(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where(function (Builder $inner) {
                $inner->where('pipeline_phase', 'closed')
                    ->where('closer_status', 'closed_lost');
            })->orWhere('stage', 'closed_lost');
        });
    }

    /**
     * @return array<string, int>
     */
    public function pipelineCounts(Workspace $workspace): array
    {
        $base = $this->workspaceQuery($workspace);
        $counts = [];

        foreach ($this->metricKeys() as $metric) {
            $counts[$metric] = (clone $base)->when(
                $metric !== 'total_leads',
                fn (Builder $q) => $this->applyMetric($q, $metric),
            )->count();
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $pipeline
     * @return array<string, float|int|null>
     */
    public function conversionRates(array $pipeline): array
    {
        $total = $pipeline['total_leads'] ?? 0;
        $booked = $pipeline['booked'] ?? 0;
        $showed = $pipeline['showed'] ?? 0;
        $closedWon = $pipeline['closed_won'] ?? 0;

        return [
            'book_to_show_rate' => $booked > 0 ? round(($showed / $booked) * 100, 1) : null,
            'show_to_close_rate' => $showed > 0 ? round(($closedWon / $showed) * 100, 1) : null,
            'overall_close_rate' => $total > 0 ? round(($closedWon / $total) * 100, 1) : null,
        ];
    }

    public function averageClosedVolume(Workspace $workspace): float
    {
        $avg = $this->scopeClosedWon($this->workspaceQuery($workspace))->avg('sale_value');

        return round((float) ($avg ?: 0), 2);
    }

    public function totalDials(Workspace $workspace): int
    {
        return LeadActivity::query()
            ->where('type', 'dial')
            ->whereHas('lead.workflow', fn (Builder $q) => $q->where('workspace_id', $workspace->id))
            ->count();
    }

    public function metricLabel(string $metric): string
    {
        return match ($metric) {
            'total_leads' => 'All leads',
            'new' => 'New leads',
            'qualified' => 'Qualified leads',
            'booked' => 'Booked appointments',
            'showed' => 'Showed / in closer pipeline',
            'closed_won' => 'Closed won',
            'not_now' => 'Not now / nurture',
            'dead' => 'Dead / closed lost',
            default => 'Pipeline leads',
        };
    }
}
