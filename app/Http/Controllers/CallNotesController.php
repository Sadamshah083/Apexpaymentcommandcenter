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

        if (! $this->access->canViewCallNotes($user, $routePrefix)) {
            abort(403, 'Call notes are not available for this account.');
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            abort(404, 'Workspace not found.');
        }

        [$isAdminView, $agents, $selectedAgentId, $selectedAgent, $scopeUserIds] = $this->resolveAgentContext(
            $request,
            $user,
            $routePrefix,
            $workspace,
        );

        $notes = null;
        if ($scopeUserIds !== []) {
            $notes = $this->notesHistory->notesForAgents($workspace, $scopeUserIds, 25);
        }

        $view = $routePrefix === 'admin.'
            ? 'communications.notes.index'
            : 'communications.notes.portal';

        $downloadQuery = $isAdminView
            ? ['agent_id' => $selectedAgentId > 0 ? $selectedAgentId : 'all']
            : [];

        return view($view, [
            'routePrefix' => $routePrefix,
            'isAdminView' => $isAdminView,
            'agents' => $agents,
            'selectedAgentId' => $selectedAgentId,
            'selectedAgent' => $selectedAgent,
            'showAllAgents' => $isAdminView && $selectedAgentId === 0,
            'notes' => $notes,
            'downloadUrl' => $scopeUserIds !== []
                ? route($routePrefix.'communications.notes.download', $downloadQuery)
                : null,
            'hubAccess' => $this->access->viewMeta($user, $routePrefix),
        ]);
    }

    public function download(Request $request): StreamedResponse
    {
        $user = $request->user();
        $routePrefix = $this->routePrefix();

        if (! $this->access->canViewCallNotes($user, $routePrefix)) {
            abort(403, 'Call notes are not available for this account.');
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            abort(404, 'Workspace not found.');
        }

        [$isAdminView, $agents, $selectedAgentId, $selectedAgent, $scopeUserIds] = $this->resolveAgentContext(
            $request,
            $user,
            $routePrefix,
            $workspace,
        );

        if ($scopeUserIds === []) {
            abort(422, 'No agents available to download notes for.');
        }

        $rows = $this->notesHistory->allNotesForAgents($workspace, $scopeUserIds);
        $safeName = $selectedAgentId > 0
            ? (preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($selectedAgent['name'] ?? 'agent')) ?: 'agent')
            : 'all-agents';
        $filename = 'call-notes-'.$safeName.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Agent', 'Number', 'Disposition', 'Notes', 'Duration (sec)', 'When']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['agent'] ?? '',
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
     * @return array{0: bool, 1: \Illuminate\Support\Collection, 2: int, 3: ?array, 4: list<int>}
     */
    protected function resolveAgentContext(Request $request, $user, string $routePrefix, $workspace): array
    {
        $tier = $this->access->tierFor($user, $routePrefix);
        $isAdminView = in_array($tier, ['admin', 'supervisor', 'team_lead', 'qa'], true);
        $agents = $isAdminView
            ? $this->notesHistory->dialerAgents($workspace, $user, $tier === 'qa' ? 'admin' : $tier)
            : collect();

        $selectedAgentId = 0;
        $selectedAgent = null;
        $scopeUserIds = [];

        if ($isAdminView) {
            $rawAgent = $request->query('agent_id', 'all');
            if ($rawAgent === null || $rawAgent === '' || $rawAgent === 'all') {
                $selectedAgentId = 0;
            } else {
                $selectedAgentId = max(0, (int) $rawAgent);
            }

            if ($selectedAgentId > 0 && ! $agents->contains(fn (array $row) => (int) $row['id'] === $selectedAgentId)) {
                $selectedAgentId = 0;
            }

            if ($selectedAgentId > 0) {
                $selectedAgent = $agents->firstWhere('id', $selectedAgentId);
                $scopeUserIds = [$selectedAgentId];
            } else {
                $selectedAgent = [
                    'id' => 0,
                    'name' => 'All agents',
                    'role' => '',
                ];
                $scopeUserIds = $agents->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            }
        } else {
            $selectedAgentId = (int) $user->id;
            $selectedAgent = [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'role' => 'Agent',
            ];
            $scopeUserIds = [(int) $user->id];
        }

        return [$isAdminView, $agents, $selectedAgentId, $selectedAgent, $scopeUserIds];
    }

    protected function routePrefix(): string
    {
        return request()->routeIs('admin.*') ? 'admin.' : 'portal.';
    }
}
