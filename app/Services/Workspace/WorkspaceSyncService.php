<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSyncEvent;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use Illuminate\Support\Facades\DB;

class WorkspaceSyncService
{
    public function record(
        Workspace $workspace,
        string $eventType,
        string $entityType,
        ?int $entityId = null,
        array $payload = [],
        ?int $actorId = null,
    ): WorkspaceSyncEvent {
        return WorkspaceSyncEvent::create([
            'workspace_id' => $workspace->id,
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload,
            'actor_id' => $actorId,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{
     *     version: string,
     *     cursor: int,
     *     events: array<int, array<string, mixed>>,
     *     workflows: array<int, array<string, mixed>>,
     *     leads: array<int, array<string, mixed>>,
     *     team: array<int, array<string, mixed>>
     * }
     */
    public function buildState(Workspace $workspace, User $user, ?int $cursor = null, ?int $workflowId = null): array
    {
        $isAdmin = $user->isWorkspaceAdmin($workspace->id);

        $eventsQuery = WorkspaceSyncEvent::where('workspace_id', $workspace->id)
            ->when($cursor !== null, fn ($q) => $q->where('id', '>', $cursor))
            ->orderBy('id')
            ->limit(50);

        $events = $eventsQuery->get();
        $latestCursor = WorkspaceSyncEvent::where('workspace_id', $workspace->id)->max('id') ?? 0;

        $workflowsQuery = $workspace->workflows()
            ->latest()
            ->withCount(['leads as assigned_leads_count' => fn ($query) => $query->whereNotNull('assigned_user_id')]);
        if ($workflowId) {
            $workflowsQuery->where('id', $workflowId);
        }
        $workflows = $workflowsQuery->get();

        $leadsQuery = WorkflowLead::query()
            ->whereIn('workflow_id', $workspace->workflows()->pluck('id'))
            ->with('assignee:id,name');

        if (! $isAdmin) {
            $leadsQuery->where('assigned_user_id', $user->id);
        }

        if ($workflowId) {
            $leadsQuery->where('workflow_id', $workflowId);
        }

        $leads = $leadsQuery
            ->orderByRaw("CASE WHEN status = 'completed' THEN 1 WHEN status = 'extracting' THEN 2 WHEN status = 'failed' THEN 3 ELSE 4 END ASC")
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        $team = $workspace->users()->get(['users.id', 'users.name', 'users.email']);

        $version = $this->computeVersion($workspace, $leads, $workflows, $team);

        return [
            'version' => $version,
            'cursor' => (int) $latestCursor,
            'events' => $events->map(fn (WorkspaceSyncEvent $event) => [
                'id' => $event->id,
                'type' => $event->event_type,
                'entity_type' => $event->entity_type,
                'entity_id' => $event->entity_id,
                'payload' => $event->payload ?? [],
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()->all(),
            'workflows' => $workflows->map(fn (Workflow $wf) => $this->serializeWorkflow($wf))->values()->all(),
            'leads' => $leads->map(fn (WorkflowLead $lead) => $this->serializeLead($lead))->values()->all(),
            'team' => $team->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot->role,
                'status' => $member->pivot->status ?? 'active',
            ])->values()->all(),
        ];
    }

    public function poll(Workspace $workspace, User $user, ?string $version = null, ?int $cursor = null, ?int $workflowId = null): array
    {
        $state = $this->buildState($workspace, $user, $cursor, $workflowId);

        return [
            'changed' => $version === null || $state['version'] !== $version,
            ...$state,
        ];
    }

    protected function computeVersion(Workspace $workspace, $leads, $workflows, $team): string
    {
        $leadStamp = $leads->max('updated_at');
        $workflowStamp = $workflows->max('updated_at');
        $teamStamp = DB::table('workspace_user')
            ->where('workspace_id', $workspace->id)
            ->max('updated_at');
        $eventStamp = WorkspaceSyncEvent::where('workspace_id', $workspace->id)->max('id') ?? 0;

        return md5(implode('|', [
            $workspace->id,
            $leadStamp,
            $workflowStamp,
            $teamStamp,
            $eventStamp,
            $leads->count(),
            $workflows->count(),
        ]));
    }

    protected function serializeWorkflow(Workflow $workflow): array
    {
        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'status' => $workflow->status,
            'original_filename' => $workflow->original_filename,
            'total_leads' => $workflow->total_leads,
            'processed_leads' => $workflow->processed_leads,
            'failed_leads' => $workflow->failed_leads,
            'assigned_leads' => (int) ($workflow->assigned_leads_count ?? $workflow->leads()->whereNotNull('assigned_user_id')->count()),
            'updated_at' => $workflow->updated_at?->toIso8601String(),
        ];
    }

    protected function serializeLead(WorkflowLead $lead): array
    {
        return [
            'id' => $lead->id,
            'workflow_id' => $lead->workflow_id,
            'assigned_user_id' => $lead->assigned_user_id,
            'assignee_name' => $lead->assignee?->name,
            'status' => $lead->status,
            'business_name' => $lead->business_name,
            'address' => $lead->address,
            'city' => $lead->city,
            'state' => $lead->state,
            'owner_name' => $lead->owner_name,
            'direct_email' => $lead->direct_email,
            'direct_phone' => $lead->direct_phone,
            'payment_processor' => $lead->payment_processor,
            'stage' => $lead->stage,
            'updated_at' => $lead->updated_at?->toIso8601String(),
        ];
    }
}
