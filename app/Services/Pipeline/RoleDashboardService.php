<?php

namespace App\Services\Pipeline;

use App\Models\User;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class RoleDashboardService
{
    public function setterLeads(Workspace $workspace, User $user, array $filters = []): LengthAwarePaginator
    {
        return $this->baseQuery($workspace, $filters)
            ->where('pipeline_phase', 'with_setter')
            ->where('assigned_user_id', $user->id)
            ->paginate(25)
            ->withQueryString();
    }

    public function setterTeamLeads(Workspace $workspace, User $user, array $filters = []): LengthAwarePaginator
    {
        return $this->setterTeamLeadsQuery($workspace, $filters)
            ->paginate(25)
            ->withQueryString();
    }

    public function closerTeamQueue(Workspace $workspace, array $filters = []): LengthAwarePaginator
    {
        return $this->handoffQueueQuery($workspace, $filters)
            ->paginate(25)
            ->withQueryString();
    }

    public function closerLeads(Workspace $workspace, User $user, array $filters = []): LengthAwarePaginator
    {
        return $this->baseQuery($workspace, $filters)
            ->where('pipeline_phase', 'with_closer')
            ->where('assigned_user_id', $user->id)
            ->paginate(25)
            ->withQueryString();
    }

    public function availableSetters(Workspace $workspace): Collection
    {
        return $workspace->users()
            ->wherePivot('role', 'appointment_setter')
            ->wherePivot('status', 'active')
            ->orderBy('users.name')
            ->get();
    }

    public function availableClosers(Workspace $workspace): Collection
    {
        return $workspace->users()
            ->wherePivot('role', 'closer')
            ->wherePivot('status', 'active')
            ->orderBy('users.name')
            ->get();
    }

    public function closerTeamLeads(Workspace $workspace, User $user, array $filters = []): LengthAwarePaginator
    {
        return $this->closerTeamLeadsQuery($workspace, $filters)
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * @return Collection<int, WorkflowLead>
     */
    public function portalLeadsForPage(
        Workspace $workspace,
        User $user,
        string $view,
        array $filters = [],
        int $page = 1,
        int $perPage = 25,
    ): Collection {
        $query = $this->portalLeadsQuery($workspace, $user, $view, $filters);

        if ($query === null) {
            return collect();
        }

        return $query
            ->forPage(max(1, $page), max(1, min($perPage, 100)))
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<WorkflowLead>|null
     */
    protected function portalLeadsQuery(Workspace $workspace, User $user, string $view, array $filters)
    {
        return match ($view) {
            'setter' => $this->baseQuery($workspace, $filters)
                ->where('pipeline_phase', 'with_setter')
                ->where('assigned_user_id', $user->id),
            'closer' => $this->baseQuery($workspace, $filters)
                ->where('pipeline_phase', 'with_closer')
                ->where('assigned_user_id', $user->id),
            'setter_team' => $this->setterTeamLeadsQuery($workspace, $filters),
            'closer_team' => $this->closerTeamLeadsQuery($workspace, $filters),
            'handoff_queue' => $this->handoffQueueQuery($workspace, $filters),
            'ae_pipeline' => $this->aePipelineQuery($workspace, $filters),
            default => null,
        };
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<WorkflowLead>
     */
    protected function aePipelineQuery(Workspace $workspace, array $filters)
    {
        $query = WorkflowLead::query()
            ->with(['campaign:id,name', 'leadList'])
            ->whereIn('workflow_id', $workspace->workflows()->pluck('id'))
            ->where('status', 'completed')
            ->whereIn('stage', ['meeting_scheduled', 'proposal_sent', 'follow_up', 'closed_won', 'closed_lost'])
            ->orderByDesc('updated_at');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                    ->orWhere('owner_name', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<WorkflowLead>
     */
    protected function setterTeamLeadsQuery(Workspace $workspace, array $filters)
    {
        $setterIds = $workspace->users()
            ->wherePivot('role', 'appointment_setter')
            ->wherePivot('status', 'active')
            ->pluck('users.id');

        return $this->baseQuery($workspace, $filters)
            ->where(function ($query) use ($setterIds) {
                $query->where(function ($assigned) use ($setterIds) {
                    $assigned->whereIn('pipeline_phase', ['with_setter', 'appointment_settled', 'with_closer', 'closed'])
                        ->whereIn('assigned_setter_id', $setterIds);
                })->orWhere(function ($unassigned) {
                    $unassigned->where('pipeline_phase', 'enriched')
                        ->where('status', 'enriched')
                        ->whereNull('assigned_user_id');
                });
            });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<WorkflowLead>
     */
    protected function closerTeamLeadsQuery(Workspace $workspace, array $filters)
    {
        $closerIds = $workspace->users()
            ->wherePivot('role', 'closer')
            ->wherePivot('status', 'active')
            ->pluck('users.id');

        return $this->baseQuery($workspace, $filters)
            ->whereIn('pipeline_phase', ['with_closer', 'closed'])
            ->where(function ($query) use ($closerIds) {
                $query->whereIn('assigned_closer_id', $closerIds)
                    ->orWhereIn('assigned_user_id', $closerIds);
            });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<WorkflowLead>
     */
    protected function handoffQueueQuery(Workspace $workspace, array $filters)
    {
        return $this->baseQuery($workspace, $filters)
            ->where('pipeline_phase', 'appointment_settled')
            ->whereNull('assigned_closer_id');
    }

    public function closerTeamMetrics(Workspace $workspace): array
    {
        $closers = $workspace->users()
            ->wherePivot('role', 'closer')
            ->wherePivot('status', 'active')
            ->get();

        return $closers->map(function (User $closer) use ($workspace) {
            $active = WorkflowLead::query()
                ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
                ->where('assigned_user_id', $closer->id)
                ->where('pipeline_phase', 'with_closer')
                ->count();

            $salesMade = WorkflowLead::query()
                ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
                ->where('assigned_closer_id', $closer->id)
                ->where('closer_status', 'sale_made')
                ->count();

            $totalClosed = WorkflowLead::query()
                ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
                ->where('assigned_closer_id', $closer->id)
                ->where('pipeline_phase', 'closed')
                ->count();

            return [
                'user' => $closer,
                'active_leads' => $active,
                'sales_made' => $salesMade,
                'total_closed' => $totalClosed,
            ];
        })->all();
    }

    public function setterTeamMetrics(Workspace $workspace): array
    {
        $setters = $workspace->users()
            ->wherePivot('role', 'appointment_setter')
            ->wherePivot('status', 'active')
            ->get();

        return $setters->map(function (User $setter) use ($workspace) {
            $active = WorkflowLead::query()
                ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
                ->where('assigned_user_id', $setter->id)
                ->where('pipeline_phase', 'with_setter')
                ->count();

            $settled = WorkflowLead::query()
                ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
                ->where('assigned_setter_id', $setter->id)
                ->whereIn('pipeline_phase', ['appointment_settled', 'with_closer', 'closed'])
                ->count();

            return [
                'user' => $setter,
                'active_leads' => $active,
                'settled_leads' => $settled,
            ];
        })->all();
    }

    protected function baseQuery(Workspace $workspace, array $filters)
    {
        $query = WorkflowLead::query()
            ->with(['workflow', 'assignee', 'setter', 'campaign', 'leadList'])
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->orderByDesc('updated_at');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                    ->orWhere('owner_name', 'like', "%{$search}%")
                    ->orWhere('input_email', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['phase'])) {
            $query->where('pipeline_phase', $filters['phase']);
        }

        if (! empty($filters['setter'])) {
            $query->where('assigned_setter_id', (int) $filters['setter']);
        }

        if (! empty($filters['closer'])) {
            $closerId = (int) $filters['closer'];
            $query->where(function ($q) use ($closerId) {
                $q->where('assigned_closer_id', $closerId)
                    ->orWhere('assigned_user_id', $closerId);
            });
        }

        if (! empty($filters['focus'])) {
            $this->applyFocusFilter($query, $filters, $workspace);
        }

        return $query;
    }

    protected function applyFocusFilter($query, array $filters, Workspace $workspace): void
    {
        switch ($filters['focus']) {
            case 'followups':
                $query->where(function ($q) {
                    $q->where(function ($inner) {
                        $inner->whereNotNull('followup_at')->where('followup_at', '<=', now());
                    })->orWhere(function ($inner) {
                        $inner->whereNotNull('schedule_at')->where('schedule_at', '<=', now());
                    });
                });
                break;
            case 'settled':
                $query->where('appointment_settled_at', '>=', now()->startOfWeek());
                break;
            case 'unworked':
                $query->whereDoesntHave('activities')
                    ->where('created_at', '>=', now()->subDays(7));
                break;
            case 'handoff':
                $query->where('pipeline_phase', 'appointment_settled')->whereNull('assigned_closer_id');
                break;
            case 'tier':
                if (filled($filters['tier'] ?? null)) {
                    $query->where('tier', $filters['tier']);
                } else {
                    $query->whereNull('tier');
                }
                break;
            case 'status':
                if (filled($filters['status'] ?? null)) {
                    $query->where('closer_status', $filters['status']);
                }
                break;
            case 'callbacks':
                $query->where(function ($q) {
                    $q->whereNotNull('followup_at')->orWhereNotNull('schedule_at');
                })->orderByRaw('COALESCE(followup_at, schedule_at) asc');
                break;
            case 'member':
                if (filled($filters['member'] ?? null)) {
                    $memberId = (int) $filters['member'];
                    $query->where(function ($q) use ($memberId) {
                        $q->where('assigned_user_id', $memberId)
                            ->orWhere('assigned_setter_id', $memberId)
                            ->orWhere('assigned_closer_id', $memberId);
                    });
                }
                break;
        }
    }
}
