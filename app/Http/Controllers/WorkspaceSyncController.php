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
                $request->query('sync_scope'),
            )
        );
    }

    /**
     * Push workspace updates over a single long-lived connection (no client polling).
     */
    public function stream(Request $request)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        $this->workspaceContext->ensureActiveMember($user, $workspace);

        $workflowId = $request->integer('workflow_id') ?: null;
        $leadId = $request->integer('lead_id') ?: null;
        $syncScope = $request->query('sync_scope');

        return response()->stream(function () use ($request, $workspace, $user, $workflowId, $leadId, $syncScope) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            $version = $request->query('v');
            $cursor = $request->has('cursor') ? $request->integer('cursor') : null;
            $idleChecks = 0;

            while (! connection_aborted() && $idleChecks < 180) {
                $payload = $this->syncService->poll(
                    $workspace,
                    $user,
                    $version,
                    $cursor,
                    $workflowId,
                    $leadId,
                    $syncScope,
                );

                if ($payload['changed'] ?? true) {
                    echo 'data: '.json_encode($payload)."\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    $version = $payload['version'] ?? $version;
                    $cursor = $payload['cursor'] ?? $cursor;
                    $idleChecks = 0;
                } else {
                    $idleChecks++;
                }

                usleep($syncScope === 'list' ? 2_000_000 : 1_000_000);
            }

            echo "event: reconnect\ndata: {}\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
