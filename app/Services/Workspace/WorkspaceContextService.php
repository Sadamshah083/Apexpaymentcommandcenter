<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\Workflow;
use App\Models\WorkflowLead;

class WorkspaceContextService
{
    public function resolveActiveWorkspace(User $user): Workspace
    {
        if (! $user->current_workspace_id) {
            $workspace = $user->workspaces()->first();

            if (! $workspace) {
                $workspace = Workspace::create([
                    'name' => $user->name."'s Workspace",
                    'admin_id' => $user->id,
                ]);
                $workspace->users()->attach($user->id, [
                    'role' => 'admin',
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }

            $user->update(['current_workspace_id' => $workspace->id]);
            $user->setRelation('currentWorkspace', $workspace);
        }

        if ($user->relationLoaded('currentWorkspace') && $user->currentWorkspace) {
            return $user->currentWorkspace;
        }

        return Workspace::findOrFail($user->current_workspace_id);
    }

    public function ensureUserIsMember(User $user, Workspace $workspace): void
    {
        if ($workspace->admin_id === $user->id) {
            if (! $workspace->users()->where('user_id', $user->id)->exists()) {
                $workspace->users()->attach($user->id, [
                    'role' => 'admin',
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }

            return;
        }

        $membership = $workspace->users()->where('user_id', $user->id)->first();

        if (! $membership || ($membership->pivot->status ?? 'active') === 'suspended') {
            abort(403);
        }
    }

    public function ensureIsAdmin(User $user, Workspace $workspace): void
    {
        if (! $user->isWorkspaceAdmin($workspace->id)) {
            abort(403);
        }
    }

    public function ensureCanManageMembers(User $user, Workspace $workspace): void
    {
        if (! $user->isWorkspaceAdmin($workspace->id)) {
            abort(403);
        }
    }

    public function ensureActiveMember(User $user, Workspace $workspace): void
    {
        if (! $user->hasActiveMembership($workspace->id)) {
            abort(403, 'Your workspace membership is not active.');
        }
    }

    public function ensureWorkflowBelongsToWorkspace(Workflow $workflow, Workspace $workspace): void
    {
        if ($workflow->workspace_id !== $workspace->id) {
            abort(403);
        }
    }

    public function ensureLeadBelongsToWorkspace(WorkflowLead $lead, Workspace $workspace): void
    {
        $lead->loadMissing('workflow');

        if ($lead->workflow->workspace_id !== $workspace->id) {
            abort(403);
        }
    }

    public function ensureListBelongsToWorkspace(\App\Models\EmailList $list, Workspace $workspace): void
    {
        if ($list->workspace_id !== null && $list->workspace_id !== $workspace->id) {
            abort(403);
        }
    }
}
