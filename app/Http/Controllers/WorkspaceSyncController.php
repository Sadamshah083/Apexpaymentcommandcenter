<?php

namespace App\Http\Controllers;

use App\Services\Workspace\WorkspaceContextService;
use App\Services\Workspace\WorkspaceSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WorkspaceSyncController extends Controller
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
        protected WorkspaceSyncService $syncService,
    ) {}

    public function poll(Request $request)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        $this->workspaceContext->ensureActiveMember($user, $workspace);

        return response()->json(
            $this->syncService->poll(
                $workspace,
                $user,
                $request->query('v'),
                $request->has('cursor') ? $request->integer('cursor') : null,
                $request->integer('workflow_id') ?: null,
                $request->integer('lead_id') ?: null,
            )
        );
    }
}
