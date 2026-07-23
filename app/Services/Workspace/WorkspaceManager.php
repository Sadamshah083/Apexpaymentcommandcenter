<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

class WorkspaceManager
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
        protected WorkspaceSyncService $syncService,
    ) {}

    public function createWorkspace(User $user, string $name): Workspace
    {
        if (! $user->isPlatformSuperAdmin()) {
            throw ValidationException::withMessages([
                'name' => 'Only the Super Admin can add workspaces.',
            ]);
        }

        $workspace = Workspace::create([
            'name' => $name,
            'admin_id' => $user->id,
        ]);

        // Keep a single platform Super Admin across workspaces — do not create extra SA users.
        $workspace->users()->attach($user->id, [
            'role' => 'super_admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $user->update(['current_workspace_id' => $workspace->id]);

        $this->syncService->record(
            $workspace,
            'workspace.created',
            'workspace',
            $workspace->id,
            ['name' => $workspace->name],
            $user->id,
        );

        return $workspace;
    }

    public function switchWorkspace(User $user, Workspace $workspace): void
    {
        $this->workspaceContext->ensureUserIsMember($user, $workspace);
        $this->workspaceContext->ensureActiveMember($user, $workspace);
        $user->update(['current_workspace_id' => $workspace->id]);

        $this->syncService->record(
            $workspace,
            'workspace.switched',
            'workspace',
            $workspace->id,
            ['name' => $workspace->name],
            $user->id,
        );
    }
}
