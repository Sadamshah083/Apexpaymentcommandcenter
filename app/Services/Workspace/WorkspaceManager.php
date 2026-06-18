<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Models\Workspace;

class WorkspaceManager
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
    ) {}

    public function createWorkspace(User $user, string $name): Workspace
    {
        $workspace = Workspace::create([
            'name' => $name,
            'admin_id' => $user->id,
        ]);

        $workspace->users()->attach($user->id, [
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $user->update(['current_workspace_id' => $workspace->id]);

        return $workspace;
    }

    public function switchWorkspace(User $user, Workspace $workspace): void
    {
        $this->workspaceContext->ensureUserIsMember($user, $workspace);
        $this->workspaceContext->ensureActiveMember($user, $workspace);
        $user->update(['current_workspace_id' => $workspace->id]);
    }
}
