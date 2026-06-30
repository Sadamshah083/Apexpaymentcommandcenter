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
        $setterIds = $workspace->users()
            ->wherePivot('role', 'appointment_setter')
            ->wherePivot('status', 'active')
            ->pluck('users.id');

        return $this->baseQuery($workspace, $filters)
            ->whereIn('pipeline_phase', ['with_setter', 'appointment_settled', 'with_closer', 'closed'])
            ->whereIn('assigned_setter_id', $setterIds)
            ->paginate(25)
            ->withQueryString();
    }

    public function closerTeamQueue(Workspace $workspace, array $filters = []): LengthAwarePaginator
    {
        return $this->baseQuery($workspace, $filters)
            ->where('pipeline_phase', 'appointment_settled')
            ->whereNull('assigned_closer_id')
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
        $closerIds = $workspace->users()
            ->wherePivot('role', 'closer')
            ->wherePivot('status', 'active')
            ->pluck('users.id');

        return $this->baseQuery($workspace, $filters)
            ->whereIn('pipeline_phase', ['with_closer', 'closed'])
            ->where(function($query) use ($closerIds) {
                $query->whereIn('assigned_closer_id', $closerIds)
                      ->orWhereIn('assigned_user_id', $closerIds);
            })
            ->paginate(25)
            ->withQueryString();
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
            ->with(['workflow', 'assignee'])
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

        return $query;
    }
}
