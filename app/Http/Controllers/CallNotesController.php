<?php

namespace App\Http\Controllers;

use App\Services\Communications\CallNotesHistoryService;
use App\Services\Communications\CommunicationsAccessService;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CallNotesController extends Controller
{
    public function __construct(
        protected CallNotesHistoryService $notesHistory,
        protected CommunicationsAccessService $access,
        protected WorkspaceContextService $workspaceContext,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $routePrefix = $this->routePrefix();

        if (! $this->access->canDial($user, $routePrefix)) {
            abort(403, 'Call notes are not available for this account.');
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            abort(404, 'Workspace not found.');
        }

        [$isAdminView, $agents, $selectedAgentId, $selectedAgent] = $this->resolveAgentContext($request, $user, $routePrefix, $workspace);

        $notes = null;
        if ($selectedAgentId > 0) {
            $notes = $this->notesHistory->notesForAgent($workspace, $selectedAgentId, 25);
        }

        $view = $routePrefix === 'admin.'
            ? 'communications.notes.index'
            : 'communications.notes.portal';

        return view($view, [
            'routePrefix' => $routePrefix,
            'isAdminView' => $isAdminView,
            'agents' => $agents,
            'selectedAgentId' => $selectedAgentId,
            'selectedAgent' => $selectedAgent,
            'notes' => $notes,
            'downloadUrl' => $selectedAgentId > 0
                ? route($routePrefix.'communications.notes.download', ['agent_id' => $selectedAgentId])
                : null,
            'hubAccess' => $this->access->viewMeta($user, $routePrefix),
        ]);
    }

    public function download(Request $request): StreamedResponse
    {
        $user = $request->user();
        $routePrefix = $this->routePrefix();

        if (! $this->access->canDial($user, $routePrefix)) {
            abort(403, 'Call notes are not available for this account.');
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            abort(404, 'Workspace not found.');
        }

        [$isAdminView, $agents, $selectedAgentId, $selectedAgent] = $this->resolveAgentContext($request, $user, $routePrefix, $workspace);

        if ($selectedAgentId <= 0 || ! $selectedAgent) {
            abort(422, 'Select an agent before downloading notes.');
        }

        $rows = $this->notesHistory->allNotesForAgent($workspace, $selectedAgentId);
        $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($selectedAgent['name'] ?? 'agent')) ?: 'agent';
        $filename = 'call-notes-'.$safeName.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $selectedAgent) {
            $handle = fopen('php://output', 'w');
            // Excel-friendly UTF-8 BOM
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Agent', 'Number', 'Disposition', 'Notes', 'Duration (sec)', 'When']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $selectedAgent['name'] ?? '',
                    $row['phone'] ?? '',
                    $row['disposition'] ?? '',
                    $row['notes'] ?? '',
                    $row['duration_sec'] ?? 0,
                    $row['when_exact'] ?: ($row['when_display'] ?? ''),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{0: bool, 1: \Illuminate\Support\Collection, 2: int, 3: ?array}
     */
    protected function resolveAgentContext(Request $request, $user, string $routePrefix, $workspace): array
    {
        $tier = $this->access->tierFor($user, $routePrefix);
        $isAdminView = in_array($tier, ['admin', 'supervisor', 'team_lead'], true);
        $agents = $isAdminView
            ? $this->notesHistory->dialerAgents($workspace, $user, $tier)
            : collect();

        $selectedAgentId = 0;
        $selectedAgent = null;

        if ($isAdminView) {
            $selectedAgentId = max(0, (int) $request->query('agent_id', 0));
            if ($selectedAgentId > 0 && ! $agents->contains(fn (array $row) => (int) $row['id'] === $selectedAgentId)) {
                $selectedAgentId = 0;
            }
            if ($selectedAgentId > 0) {
                $selectedAgent = $agents->firstWhere('id', $selectedAgentId);
            }
        } else {
            $selectedAgentId = (int) $user->id;
            $selectedAgent = [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'role' => 'Agent',
            ];
        }

        return [$isAdminView, $agents, $selectedAgentId, $selectedAgent];
    }

    protected function routePrefix(): string
    {
        return request()->routeIs('admin.*') ? 'admin.' : 'portal.';
    }
}
