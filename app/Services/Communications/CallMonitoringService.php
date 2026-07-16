<?php

namespace App\Services\Communications;

use App\Models\CommunicationCallLog;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Support\Facades\Auth;

class CallMonitoringService
{
    /** @var array<string, bool> */
    protected array $monitoringExcludedExtensions = [];

    /** @var array<int, bool> */
    protected array $monitoringExcludedUserIds = [];

    public function __construct(
        protected MorpheusHubService $hub,
        protected CommunicationsAgentService $agents,
        protected WorkspaceContextService $workspaceContext,
        protected MorpheusCallEventService $callEvents,
    ) {}

    /**
     * Prefer the active workspace; if it has no monitorable agents, fall back to another
     * membership (or any workspace for admins) that has a Call Monitoring roster.
     */
    public function resolveWorkspaceForMonitoring(?\App\Models\User $user = null, ?Workspace $preferred = null): ?Workspace
    {
        $user = $user ?: Auth::user();
        $preferred = $preferred ?: ($user ? $this->workspaceContext->resolveActiveWorkspace($user) : null);
        if (! $preferred) {
            return null;
        }

        try {
            if ($this->agents->listMonitorableDirectory($preferred) !== []) {
                return $preferred;
            }
        } catch (\Throwable) {
            // Fall through to alternate workspaces.
        }

        $candidates = collect();
        if ($user) {
            try {
                $candidates = $user->workspaces()
                    ->wherePivot('status', 'active')
                    ->get();
            } catch (\Throwable) {
                $candidates = collect();
            }
        }

        if ($candidates->isEmpty() && $user && method_exists($user, 'canAccessAdminPortal') && $user->canAccessAdminPortal()) {
            $candidates = Workspace::query()->orderBy('id')->get();
        }

        foreach ($candidates as $candidate) {
            if ((int) $candidate->id === (int) $preferred->id) {
                continue;
            }
            try {
                if ($this->agents->listMonitorableDirectory($candidate) !== []) {
                    return $candidate;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $preferred;
    }

    /**
     * @return array{
     *   ok: bool,
     *   generated_at: string,
     *   version: int,
     *   summary: array<string, int>,
     *   rows: array<int, array<string, mixed>>,
     *   warnings: array<int, string>
     * }
     */
    public function snapshot(?Workspace $workspace = null, bool $light = false, bool $probeConnected = false): array
    {
        $user = Auth::user();
        $workspace = $workspace
            ? $this->resolveWorkspaceForMonitoring($user instanceof \App\Models\User ? $user : null, $workspace)
            : $this->resolveWorkspaceForMonitoring($user instanceof \App\Models\User ? $user : null);
        $warnings = [];

        // Drop abandoned ringing / missed-hangup connected states before painting the board.
        try {
            // Only prune abandoned ringing — never auto-drop an active connected call.
            $this->callEvents->pruneStaleLiveStates(ringMaxSec: 90, connectedIdleSec: 0);
        } catch (\Throwable) {
            // Snapshot should still render.
        }

        $agentsByExt = collect();
        $monitorRoster = [];
        $excludedExtensions = [];
        $excludedUserIds = [];
        if ($workspace) {
            try {
                // Light polls must still resolve role badges (DB only). Full polls can enrich via Morpheus.
                $agents = $light
                    ? $this->agents->listLocalExtensionDirectory($workspace)
                    : $this->agents->listForWorkspace($workspace);

                // Always load the full monitorable roster (extension optional) so Admin / Team Lead
                // still see NOT LOGGED IN agents with real usernames when nobody is on a call.
                try {
                    $monitorRoster = $this->agents->listMonitorableDirectory($workspace);
                } catch (\Throwable) {
                    $monitorRoster = [];
                }

                foreach (array_merge($agents, $monitorRoster) as $agent) {
                    if (! is_array($agent)) {
                        continue;
                    }
                    $role = (string) ($agent['role'] ?? '');
                    $label = (string) ($agent['role_label'] ?? '');
                    if (AgentPresenceService::isExcludedFromMonitoring($role, $label)) {
                        $ext = preg_replace('/\D/', '', (string) ($agent['morpheus_extension_num'] ?? '')) ?? '';
                        if ($ext !== '') {
                            $excludedExtensions[$ext] = true;
                        }
                        $uid = (int) ($agent['user_id'] ?? 0);
                        if ($uid > 0) {
                            $excludedUserIds[$uid] = true;
                        }
                    }
                }

                $this->monitoringExcludedExtensions = $excludedExtensions;
                $this->monitoringExcludedUserIds = $excludedUserIds;

                $agentsByExt = collect($agents)
                    ->filter(fn (array $a) => filled($a['morpheus_extension_num'] ?? null))
                    ->filter(fn (array $a) => ! AgentPresenceService::isExcludedFromMonitoring(
                        (string) ($a['role'] ?? ''),
                        (string) ($a['role_label'] ?? ''),
                    ))
                    ->filter(fn (array $a) => AgentPresenceService::isMonitorableRole((string) ($a['role'] ?? '')))
                    ->keyBy(fn (array $a) => preg_replace('/\D/', '', (string) $a['morpheus_extension_num']));

                // Prefer richer Morpheus-backed agent rows when available; fill gaps from roster.
                if ($monitorRoster !== []) {
                    $byUser = collect($agents)->keyBy(fn (array $a) => (int) ($a['user_id'] ?? 0));
                    foreach ($monitorRoster as $rosterAgent) {
                        $uid = (int) ($rosterAgent['user_id'] ?? 0);
                        if ($uid <= 0 || $byUser->has($uid)) {
                            continue;
                        }
                        $byUser->put($uid, $rosterAgent);
                    }
                    // Keep agentsByExt keyed by extension for live-call matching.
                    foreach ($byUser as $agentRow) {
                        if (! is_array($agentRow)) {
                            continue;
                        }
                        $ext = preg_replace('/\D/', '', (string) ($agentRow['morpheus_extension_num'] ?? '')) ?? '';
                        if ($ext === '' || $agentsByExt->has($ext)) {
                            continue;
                        }
                        if (! AgentPresenceService::isMonitorableRole((string) ($agentRow['role'] ?? ''))) {
                            continue;
                        }
                        if (AgentPresenceService::isExcludedFromMonitoring(
                            (string) ($agentRow['role'] ?? ''),
                            (string) ($agentRow['role_label'] ?? ''),
                        )) {
                            continue;
                        }
                        $agentsByExt->put($ext, $agentRow);
                    }
                }
            } catch (\Throwable $e) {
                $warnings[] = 'Could not load workspace agents.';
            }
        }

        // Roster used for idle / offline / break enrichment (always includes names).
        $rosterAgents = $monitorRoster !== []
            ? $monitorRoster
            : $agentsByExt->values()->all();

        $activeCalls = [];
        if (! $light) {
            try {
                $activeCalls = $this->hub->activeCallsFresh();
            } catch (\Throwable $e) {
                $warnings[] = 'Could not load live Morpheus calls.';
            }

            if ($probeConnected) {
                $this->refreshConnectedFromHub();
            }
        }

        $localByUuid = $this->recentLocalLogsByUuid($workspace);
        $rowsById = [];

        foreach ($activeCalls as $call) {
            if (! is_array($call)) {
                continue;
            }

            $row = $this->mapActiveCallRow($call, $agentsByExt, $localByUuid);
            if ($row === null) {
                continue;
            }

            $rowsById[(string) $row['id']] = $row;
        }

        foreach ($this->callEvents->listLiveStates() as $state) {
            $row = $this->mapWebhookStateRow($state, $agentsByExt, $localByUuid);
            if ($row === null) {
                continue;
            }

            $id = (string) $row['id'];
            if (! isset($rowsById[$id]) || ($row['status_group'] === 'incall' && $rowsById[$id]['status_group'] !== 'incall')) {
                $rowsById[$id] = $row;
            } else {
                $rowsById[$id] = $this->mergeRows($rowsById[$id], $row);
            }
        }

        foreach ($this->recentLiveLocalRows($workspace, $agentsByExt) as $row) {
            $id = (string) $row['id'];
            if (! isset($rowsById[$id])) {
                $rowsById[$id] = $row;
            }
        }

        $rows = $this->rejectExcludedMonitoringRows(
            $this->dedupeByAgent($this->dedupeRows(array_values($rowsById)))
        );

        usort($rows, function (array $a, array $b) {
            $rank = ['incall' => 0, 'queue' => 1, 'ringing' => 2, 'waiting' => 2, 'dead' => 3];
            $ra = $rank[$a['status_group']] ?? 9;
            $rb = $rank[$b['status_group']] ?? 9;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return ($b['timer_sec'] ?? 0) <=> ($a['timer_sec'] ?? 0);
        });

        $inCallShort = collect($rows)->where('bucket', 'incall_short')->count();
        $inCallLong = collect($rows)->where('bucket', 'incall_long')->count();
        $ringing = collect($rows)->where('bucket', 'ringing')->count();
        $queue = collect($rows)->where('bucket', 'queue')->count();
        $deadFromLive = collect($rows)->where('bucket', 'dead')->values()->all();
        $inCall = $inCallShort + $inCallLong;

        $notInCall = [];
        $disposition = [];
        $onBreak = [];
        $onLunch = [];
        $notLoggedIn = [];
        $deadRecent = [];
        if ($workspace) {
            try {
                $breakRows = $this->rejectExcludedMonitoringRows(
                    $this->buildBreakLunchRows($workspace, $rosterAgents, $rows)
                );
                foreach ($breakRows as $breakRow) {
                    if (($breakRow['bucket'] ?? '') === 'lunch') {
                        $onLunch[] = $breakRow;
                    } else {
                        $onBreak[] = $breakRow;
                    }
                }
            } catch (\Throwable) {
                $warnings[] = 'Could not load break/lunch agents.';
            }

            try {
                $notInCall = $this->rejectExcludedMonitoringRows(
                    $this->buildNotInCallRows($workspace, $rosterAgents, $rows)
                );
            } catch (\Throwable) {
                $warnings[] = 'Could not load idle agents.';
            }

            try {
                $disposition = $this->rejectExcludedMonitoringRows(
                    $this->buildDispositionRows($workspace, $rosterAgents, $rows)
                );
            } catch (\Throwable) {
                $warnings[] = 'Could not load disposition agents.';
            }

            // DB break/lunch wins over presence-derived idle/disposition rows.
            $breakUserIds = [];
            foreach (array_merge($onBreak, $onLunch) as $breakRow) {
                $uid = (int) ($breakRow['user_id'] ?? 0);
                if ($uid > 0) {
                    $breakUserIds[$uid] = true;
                }
            }
            if ($breakUserIds !== []) {
                $notInCall = array_values(array_filter(
                    $notInCall,
                    static fn (array $row): bool => ! isset($breakUserIds[(int) ($row['user_id'] ?? 0)])
                ));
                $disposition = array_values(array_filter(
                    $disposition,
                    static fn (array $row): bool => ! isset($breakUserIds[(int) ($row['user_id'] ?? 0)])
                ));
            }

            try {
                $notLoggedIn = $this->rejectExcludedMonitoringRows(
                    $this->buildNotLoggedInRows(
                        $workspace,
                        $rosterAgents,
                        $rows,
                        array_merge($notInCall, $onBreak, $onLunch),
                        $disposition
                    )
                );
            } catch (\Throwable) {
                $warnings[] = 'Could not load offline agents.';
            }

            try {
                $deadRecent = $this->rejectExcludedMonitoringRows(
                    $this->recentDeadCallRows($workspace, $agentsByExt)
                );
            } catch (\Throwable) {
                // Dead board is optional enrichment.
            }
        }

        // Deduplicate dead live + recent missed/ended legs.
        $deadById = [];
        foreach (array_merge($deadFromLive, $deadRecent) as $deadRow) {
            $id = (string) ($deadRow['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $deadById[$id] = $deadRow;
        }
        $deadRows = $this->rejectExcludedMonitoringRows(array_values($deadById));
        $dead = count($deadRows);
        $notInCallCount = count($notInCall);
        $dispositionCount = count($disposition);
        $breakCount = count($onBreak);
        $lunchCount = count($onLunch);
        $notLoggedInCount = count($notLoggedIn);
        $loggedInCount = $notInCallCount + $dispositionCount + $breakCount + $lunchCount + count($rows);

        // Attach dial mode onto live rows when presence knows it.
        $rows = $this->attachDialModeToLiveRows($workspace, $rows);

        $presenceVersion = $workspace
            ? app(AgentPresenceService::class)->presenceVersion($workspace)
            : 0;

        return [
            'ok' => true,
            'generated_at' => now()->toIso8601String(),
            'version' => $this->callEvents->monitoringVersion(),
            'presence_version' => $presenceVersion,
            'summary' => [
                'total' => $loggedInCount + $notLoggedInCount,
                'in_call' => $inCall,
                'in_call_short' => $inCallShort,
                'in_call_long' => $inCallLong,
                'waiting' => $ringing,
                'ringing' => $ringing,
                'queue' => $queue,
                'dead' => $dead,
                'disposition' => $dispositionCount,
                'break' => $breakCount,
                'lunch' => $lunchCount,
                'not_in_call' => $notInCallCount,
                'logged_in' => $loggedInCount,
                'not_logged_in' => $notLoggedInCount,
                'active_calls' => $inCall + $queue,
                'agents_online' => $loggedInCount,
            ],
            'tables' => [
                'ringing' => collect($rows)->where('bucket', 'ringing')->values()->all(),
                'incall_short' => collect($rows)->where('bucket', 'incall_short')->values()->all(),
                'incall_long' => collect($rows)->where('bucket', 'incall_long')->values()->all(),
                'queue' => collect($rows)->where('bucket', 'queue')->values()->all(),
                'dead' => $deadRows,
                'disposition' => $disposition,
                'break' => $onBreak,
                'lunch' => $onLunch,
                'not_in_call' => $notInCall,
                'not_logged_in' => $notLoggedIn,
            ],
            'rows' => $rows,
            'not_in_call' => $notInCall,
            'not_logged_in' => $notLoggedIn,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $call
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $agentsByExt
     * @param  array<string, array<string, mixed>>  $localByUuid
     * @return array<string, mixed>|null
     */
    protected function mapActiveCallRow(array $call, $agentsByExt, array $localByUuid): ?array
    {
        $uuid = (string) (
            $call['call_uuid']
            ?? $call['uuid']
            ?? $call['id']
            ?? $call['origination_uuid']
            ?? ''
        );

        $state = strtoupper(trim((string) (
            $call['state']
            ?? $call['status']
            ?? $call['call_state']
            ?? $call['call_status']
            ?? ''
        )));

        if (in_array($state, ['DOWN', 'HANGUP', 'ENDED', 'COMPLETED'], true)) {
            return null;
        }

        $billsec = (int) ($call['billsec'] ?? $call['duration_sec'] ?? 0);
        $ageSec = (int) ($call['age_sec'] ?? 0);
        $webhook = $uuid !== '' ? $this->callEvents->getCallState($uuid) : null;
        $destination = (string) (
            $call['destination_number']
            ?? $call['phone_number']
            ?? $call['destination']
            ?? $call['to']
            ?? $call['dest']
            ?? ''
        );
        $destDigits = preg_replace('/\D/', '', $destination) ?? '';
        // Prefer destination-answered signal — agent leg alone is not both-sides connected.
        $answered = (
            filter_var($call['destination_answered'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var($call['destination_connected'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || (is_array($webhook) && (
                ($webhook['destination_answered'] ?? false)
                || ($webhook['destination_connected'] ?? false)
            ))
            || (
                $billsec >= 1
                && filled($call['bridged_to'] ?? $call['bridge_uuid'] ?? null)
                && filled($call['answer_time'] ?? $call['answered_at'] ?? null)
            )
            // PSTN destination leg showing answered/active on Morpheus /calls
            || (
                strlen($destDigits) >= 10
                && in_array($state, ['ACTIVE', 'ANSWERED', 'CONNECTED', 'BRIDGED', 'UP', 'TALKING'], true)
                && (
                    filled($call['answer_time'] ?? $call['answered_at'] ?? null)
                    || filter_var($call['answered'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    || $billsec >= 1
                    || filled($call['bridged_to'] ?? $call['bridge_uuid'] ?? null)
                )
            )
        );

        if ($answered && $uuid !== '' && (! is_array($webhook) || ! ($webhook['destination_answered'] ?? false))) {
            $this->callEvents->markDestinationConnected(
                $uuid,
                $destination,
                max(1, $billsec, $ageSec),
                'active_calls',
            );
            $webhook = $this->callEvents->getCallState($uuid) ?: $webhook;
        }

        return $this->buildRow([
            'uuid' => $uuid,
            'state' => $state,
            'answered' => $answered,
            'billsec' => max($billsec, (int) ($webhook['billsec'] ?? 0)),
            'age_sec' => $ageSec,
            'connected_at' => $webhook['connected_at'] ?? ($call['answer_time'] ?? $call['answered_at'] ?? null),
            'destination' => $destination,
            'caller' => (string) (
                $call['caller_number']
                ?? $call['from']
                ?? $call['caller_id_number']
                ?? ''
            ),
            'extension' => null,
            'campaign' => (string) ($call['campaign'] ?? $call['queue'] ?? '—'),
            'direction' => (string) ($call['direction'] ?? ''),
            'raw' => $call,
        ], $agentsByExt, $localByUuid);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $agentsByExt
     * @param  array<string, array<string, mixed>>  $localByUuid
     * @return array<string, mixed>|null
     */
    protected function mapWebhookStateRow(array $state, $agentsByExt, array $localByUuid): ?array
    {
        if (! ($state['live'] ?? false)) {
            return null;
        }

        $uuid = (string) ($state['uuid'] ?? '');
        $bothSidesConnected = (bool) ($state['destination_answered'] ?? false)
            || (bool) ($state['destination_connected'] ?? false);
        $billsec = (int) ($state['billsec'] ?? 0);
        $connectedAt = $state['connected_at'] ?? null;
        if ($bothSidesConnected && ! filled($connectedAt)) {
            $connectedAt = $state['updated_at'] ?? now()->toIso8601String();
        }
        $ageSec = 0;
        if (filled($connectedAt)) {
            try {
                $ageSec = max(0, \Carbon\Carbon::parse((string) $connectedAt)->diffInSeconds(now()));
            } catch (\Throwable) {
                $ageSec = max(0, $billsec);
            }
        }

        return $this->buildRow([
            'uuid' => $uuid,
            'state' => $bothSidesConnected ? 'INCALL' : 'RINGING',
            'answered' => $bothSidesConnected,
            'billsec' => $bothSidesConnected ? max($billsec, $ageSec, 1) : 0,
            'age_sec' => $ageSec,
            'connected_at' => $connectedAt,
            'destination' => (string) ($state['destination'] ?? ''),
            'caller' => '',
            'extension' => (string) ($state['from_extension'] ?? ''),
            'campaign' => '—',
            'direction' => 'outbound',
            'raw' => $state,
        ], $agentsByExt, $localByUuid);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $agentsByExt
     * @return array<int, array<string, mixed>>
     */
    protected function recentLiveLocalRows(?Workspace $workspace, $agentsByExt): array
    {
        if (! $workspace) {
            return [];
        }

        $logs = CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('updated_at', '>=', now()->subMinutes(3))
            ->whereIn('status', ['initiated', 'ringing', 'active', 'connected', 'talking', 'bridging'])
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $rows = [];
        $seenLeg = [];
        foreach ($logs as $log) {
            $uuid = (string) ($log->morpheus_call_uuid ?: ('local:'.$log->id));
            $tracked = null;
            if ($log->morpheus_call_uuid) {
                $tracked = $this->callEvents->getCallState((string) $log->morpheus_call_uuid);
                if (is_array($tracked) && ($tracked['live'] ?? null) === false) {
                    continue;
                }
                // Do not resurrect ghost "connected" call logs after hangup cleared (or never set) live cache.
                if (! is_array($tracked) || ! ($tracked['live'] ?? false)) {
                    continue;
                }
            } else {
                // Local-only rows without a Morpheus UUID: ringing window only.
                if (! in_array(strtolower((string) $log->status), ['initiated', 'ringing'], true)) {
                    continue;
                }
                if (! $log->updated_at || $log->updated_at->lt(now()->subSeconds(90))) {
                    continue;
                }
            }

            $legKey = $this->legKey((string) ($log->from_extension ?? ''), (string) ($log->to_phone ?? ''));
            if ($legKey !== '' && isset($seenLeg[$legKey])) {
                continue;
            }
            if ($legKey !== '') {
                $seenLeg[$legKey] = true;
            }
            $ageSec = max(0, $log->created_at?->diffInSeconds(now()) ?? 0);
            $webhook = is_array($tracked) ? $tracked : null;
            $bothSidesConnected = is_array($webhook) && (
                ($webhook['destination_answered'] ?? false)
                || ($webhook['destination_connected'] ?? false)
            );
            // Display-only: never write markDestinationConnected from call-log status (that created ghosts).
            if (! $bothSidesConnected && in_array(strtolower((string) $log->status), ['connected', 'talking'], true)
                && is_array($webhook) && ($webhook['live'] ?? false)
            ) {
                $bothSidesConnected = true;
            }
            $answered = $bothSidesConnected;

            $connectedAt = is_array($webhook) ? ($webhook['connected_at'] ?? null) : null;
            $timerSec = 0;
            if ($answered && filled($connectedAt)) {
                try {
                    $timerSec = max(0, (int) \Carbon\Carbon::parse((string) $connectedAt)->diffInSeconds(now()));
                } catch (\Throwable) {
                    $timerSec = max(0, (int) ($log->duration_sec ?? 0));
                }
            } elseif ($answered) {
                $timerSec = max(0, (int) ($log->duration_sec ?? 0));
            }

            $row = $this->buildRow([
                'uuid' => $uuid,
                'state' => $answered ? 'INCALL' : 'RINGING',
                'answered' => $answered,
                'billsec' => $timerSec,
                'age_sec' => $ageSec,
                'connected_at' => $connectedAt,
                'destination' => (string) ($log->to_phone ?? ''),
                'caller' => (string) ($log->from_phone ?? ''),
                'extension' => (string) ($log->from_extension ?? ''),
                'campaign' => '—',
                'direction' => (string) ($log->direction ?? ''),
                'raw' => [],
            ], $agentsByExt, [
                $uuid => [
                    'user_id' => $log->user_id,
                    'agent_name' => $log->user?->name,
                    'direction' => $log->direction,
                    'from_extension' => $log->from_extension,
                ],
            ]);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $agentsByExt
     * @param  array<string, array<string, mixed>>  $localByUuid
     * @return array<string, mixed>|null
     */
    protected function buildRow(array $input, $agentsByExt, array $localByUuid): ?array
    {
        $uuid = trim((string) ($input['uuid'] ?? ''));
        $state = strtoupper(trim((string) ($input['state'] ?? '')));
        $answered = (bool) ($input['answered'] ?? false);
        $billsec = (int) ($input['billsec'] ?? 0);
        $ageSec = (int) ($input['age_sec'] ?? 0);

        $statusGroup = 'ringing';
        $status = 'RINGING';
        $color = 'blue';
        $bucket = 'ringing';
        // Timer starts only when both sides are connected.
        $timerSec = 0;

        if (in_array($state, ['DEAD'], true)) {
            $statusGroup = 'dead';
            $status = 'DEAD';
            $color = 'dead';
            $bucket = 'dead';
            $timerSec = 0;
        } elseif ($answered) {
            $statusGroup = 'incall';
            $connectedAt = $input['connected_at'] ?? null;
            if (filled($connectedAt)) {
                try {
                    $timerSec = max(0, (int) \Carbon\Carbon::parse((string) $connectedAt)->diffInSeconds(now()));
                } catch (\Throwable) {
                    $timerSec = max(0, $billsec);
                }
            } else {
                $timerSec = max(0, $billsec);
            }

            if ($timerSec > 120) {
                $status = 'INCALL >2M';
                $color = 'pink_long';
                $bucket = 'incall_long';
            } else {
                $status = 'INCALL ≤2M';
                $color = 'pink';
                $bucket = 'incall_short';
            }
        } elseif (in_array($state, ['QUEUE', 'QUEUED', 'HOLD', 'HELD'], true)) {
            $statusGroup = 'queue';
            $status = 'QUEUE';
            $color = 'queue';
            $bucket = 'queue';
            $timerSec = 0;
        } else {
            $statusGroup = 'ringing';
            $status = 'RINGING';
            $color = 'blue';
            $bucket = 'ringing';
            $timerSec = 0;
        }

        $destination = (string) ($input['destination'] ?? '');
        $caller = (string) ($input['caller'] ?? '');
        $extension = preg_replace('/\D/', '', trim((string) ($input['extension'] ?? ''))) ?? '';
        if ($extension === '') {
            $extension = $this->extractExtension($input['raw'] ?? [], $caller, $destination);
        }

        $local = $uuid !== '' ? ($localByUuid[$uuid] ?? null) : null;

        // Call logs often know the agent extension when Morpheus/webhook state does not.
        if ($extension === '' && filled($local['from_extension'] ?? null)) {
            $extension = preg_replace('/\D/', '', (string) $local['from_extension']) ?? '';
        }

        $agent = $extension !== ''
            ? ($agentsByExt->get($extension) ?? null)
            : null;

        // Resolve station by known agent when extension is missing but user is known (Jacob Khan → 1015).
        if ($agent === null && $agentsByExt->isNotEmpty()) {
            $userId = (int) ($local['user_id'] ?? 0);
            $agentName = trim((string) ($local['agent_name'] ?? ''));
            $agent = $agentsByExt->first(function (array $candidate) use ($userId, $agentName) {
                if ($userId > 0 && (int) ($candidate['user_id'] ?? 0) === $userId) {
                    return true;
                }

                return $agentName !== ''
                    && strcasecmp(trim((string) ($candidate['name'] ?? '')), $agentName) === 0;
            }) ?: null;

            if (is_array($agent) && $extension === '') {
                $extension = preg_replace('/\D/', '', (string) ($agent['morpheus_extension_num'] ?? '')) ?? '';
            }
        }

        $userId = (int) ($local['user_id'] ?? $agent['user_id'] ?? 0);
        if ($this->isExcludedMonitoringTarget($extension, $userId, $agent)) {
            return null;
        }

        $userName = $this->resolveAgentDisplayName(
            is_array($agent) ? $agent : null,
            [
                'name' => $local['agent_name'] ?? null,
                'email' => $local['agent_email'] ?? null,
            ],
            $extension
        );

        return [
            'id' => $uuid !== '' ? $uuid : ('live:'.md5(json_encode($input))),
            'station' => $extension !== '' ? $extension : '—',
            'extension' => $extension,
            'user' => $userName,
            'user_id' => $userId,
            'role_label' => (string) ($agent['role_label'] ?? ''),
            'status' => $status,
            'status_group' => $statusGroup,
            'bucket' => $bucket,
            'timer_sec' => $timerSec,
            'timer_label' => $this->formatTimer($timerSec),
            'campaign' => (string) ($input['campaign'] ?? '—'),
            'destination' => $destination,
            'direction' => (string) ($input['direction'] ?: ($local['direction'] ?? '')),
            'color' => $color,
            'under_two_minutes' => $statusGroup === 'incall' && $timerSec <= 120,
            'connected_at' => $input['connected_at'] ?? null,
        ];
    }

    /**
     * Prefer a real workspace display name over stale/blank call-log or presence labels.
     *
     * @param  array<string, mixed>|null  $agent
     * @param  array<string, mixed>|null  $fallback
     */
    protected function resolveAgentDisplayName(?array $agent, ?array $fallback = null, ?string $extension = null): string
    {
        $candidates = [
            $agent['name'] ?? null,
            $agent['morpheus_username'] ?? null,
            $agent['caller_id_name'] ?? null,
            $fallback['name'] ?? null,
            $agent['email'] ?? null,
            $fallback['email'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $name = trim((string) $candidate);
            if ($name === '') {
                continue;
            }
            if (strcasecmp($name, 'Agent') === 0 || strcasecmp($name, 'Unknown') === 0) {
                continue;
            }
            if (str_contains($name, '@')) {
                $local = strstr($name, '@', true);

                return ($local !== false && $local !== '') ? $local : $name;
            }

            return $name;
        }

        $ext = preg_replace('/\D/', '', (string) ($extension ?? $agent['morpheus_extension_num'] ?? '')) ?? '';

        return $ext !== '' ? 'Ext '.$ext : 'Unknown';
    }

    /**
     * @param  array<string, mixed>|null  $agent
     */
    protected function isExcludedMonitoringTarget(string $extension, int $userId, ?array $agent = null): bool
    {
        if ($extension !== '' && isset($this->monitoringExcludedExtensions[$extension])) {
            return true;
        }

        if ($userId > 0 && isset($this->monitoringExcludedUserIds[$userId])) {
            return true;
        }

        if (is_array($agent) && AgentPresenceService::isExcludedFromMonitoring(
            (string) ($agent['role'] ?? ''),
            (string) ($agent['role_label'] ?? ''),
        )) {
            return true;
        }

        return false;
    }

    /**
     * Drop Super Admin / Admin (and other non-agent) rows that still slipped through.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function rejectExcludedMonitoringRows(array $rows): array
    {
        return array_values(array_filter($rows, function (array $row) {
            $ext = preg_replace('/\D/', '', (string) ($row['station'] ?? $row['extension'] ?? '')) ?? '';
            $uid = (int) ($row['user_id'] ?? 0);
            if ($this->isExcludedMonitoringTarget($ext, $uid)) {
                return false;
            }

            return ! AgentPresenceService::isExcludedFromMonitoring(
                null,
                (string) ($row['role_label'] ?? ''),
            );
        }));
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $secondary
     * @return array<string, mixed>
     */
    protected function mergeRows(array $primary, array $secondary): array
    {
        if (($secondary['status_group'] ?? '') === 'incall' && ($primary['status_group'] ?? '') !== 'incall') {
            return $secondary;
        }

        if (($primary['status_group'] ?? '') === 'incall'
            && ($secondary['timer_sec'] ?? 0) > ($primary['timer_sec'] ?? 0)
        ) {
            $primary['timer_sec'] = $secondary['timer_sec'];
            $primary['timer_label'] = $secondary['timer_label'];
            $primary['under_two_minutes'] = $secondary['under_two_minutes'] ?? $primary['under_two_minutes'] ?? false;
        }

        foreach (['user', 'role_label', 'destination', 'station', 'extension'] as $field) {
            if ((! filled($primary[$field] ?? null) || $primary[$field] === '—' || $primary[$field] === 'Unknown')
                && filled($secondary[$field] ?? null)
            ) {
                $primary[$field] = $secondary[$field];
            }
        }

        // Never wipe a known team role with an empty light-poll payload.
        if (! filled($primary['role_label'] ?? null) && filled($secondary['role_label'] ?? null)) {
            $primary['role_label'] = $secondary['role_label'];
        }

        return $primary;
    }

    /**
     * @param  array<string, mixed>  $call
     */
    protected function extractExtension(array $call, string $caller, string $destination): string
    {
        foreach ([
            $call['from_extension'] ?? null,
            $call['extension'] ?? null,
            $call['agent_extension'] ?? null,
            $call['caller_extension'] ?? null,
        ] as $candidate) {
            $digits = preg_replace('/\D/', '', (string) $candidate);
            if ($digits !== '' && strlen($digits) <= 6) {
                return $digits;
            }
        }

        foreach ([$caller, $destination] as $value) {
            $digits = preg_replace('/\D/', '', (string) $value);
            if ($digits !== '' && strlen($digits) >= 3 && strlen($digits) <= 5) {
                return $digits;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    protected function preferRow(array $a, array $b): array
    {
        $rank = ['incall' => 0, 'queue' => 1, 'ringing' => 2, 'waiting' => 2, 'dead' => 3];
        $ra = $rank[$a['status_group'] ?? ''] ?? 9;
        $rb = $rank[$b['status_group'] ?? ''] ?? 9;

        if ($rb < $ra) {
            $winner = $b;
            $loser = $a;
        } elseif ($ra < $rb) {
            $winner = $a;
            $loser = $b;
        } elseif ((int) ($b['timer_sec'] ?? 0) > (int) ($a['timer_sec'] ?? 0)) {
            $winner = $b;
            $loser = $a;
        } else {
            $winner = $a;
            $loser = $b;
        }

        foreach (['station', 'extension', 'user', 'role_label', 'destination', 'connected_at'] as $field) {
            $winnerVal = $winner[$field] ?? null;
            $loserVal = $loser[$field] ?? null;
            $winnerEmpty = ! filled($winnerVal) || $winnerVal === '—' || $winnerVal === 'Unknown';
            $loserUseful = filled($loserVal) && $loserVal !== '—' && $loserVal !== 'Unknown';
            if ($winnerEmpty && $loserUseful) {
                $winner[$field] = $loserVal;
            }
        }

        if ((! filled($winner['user_id'] ?? null) || (int) $winner['user_id'] === 0)
            && filled($loser['user_id'] ?? null)
            && (int) $loser['user_id'] > 0
        ) {
            $winner['user_id'] = (int) $loser['user_id'];
        }

        if (($winner['station'] ?? '—') === '—' && filled($winner['extension'] ?? null) && $winner['extension'] !== '—') {
            $winner['station'] = (string) $winner['extension'];
        }

        return $winner;
    }

    /**
     * Collapse the same destination into one row even when one source lacks station.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function dedupeRows(array $rows): array
    {
        $best = [];
        foreach ($rows as $row) {
            $ext = preg_replace('/\D/', '', (string) ($row['station'] ?? $row['extension'] ?? '')) ?? '';
            $dest = preg_replace('/\D/', '', (string) ($row['destination'] ?? '')) ?? '';
            if (strlen($dest) > 10) {
                $dest = substr($dest, -10);
            }

            // Prefer destination identity so "|2092592594" merges with "1015|2092592594".
            $key = $dest !== '' ? ('dest:'.$dest) : $this->legKey($ext, $dest);
            if ($key === '') {
                $key = 'id:'.(string) ($row['id'] ?? md5(json_encode($row)));
            }

            if (! isset($best[$key])) {
                $best[$key] = $row;
                continue;
            }

            $best[$key] = $this->preferRow($best[$key], $row);
        }

        return array_values($best);
    }

    /**
     * One live row per agent/station — old destination legs must not duplicate INCALL rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function dedupeByAgent(array $rows): array
    {
        $liveGroups = ['incall', 'ringing', 'waiting', 'queue'];
        $bestByAgent = [];
        $passthrough = [];

        foreach ($rows as $row) {
            $group = (string) ($row['status_group'] ?? '');
            if (! in_array($group, $liveGroups, true)) {
                $passthrough[] = $row;
                continue;
            }

            $uid = (int) ($row['user_id'] ?? 0);
            $ext = preg_replace('/\D/', '', (string) ($row['station'] ?? $row['extension'] ?? '')) ?? '';
            $key = $uid > 0 ? ('user:'.$uid) : ($ext !== '' ? ('ext:'.$ext) : '');
            if ($key === '') {
                $passthrough[] = $row;
                continue;
            }

            if (! isset($bestByAgent[$key])) {
                $bestByAgent[$key] = $row;
                continue;
            }

            $bestByAgent[$key] = $this->preferFreshestLiveRow($bestByAgent[$key], $row);
        }

        return array_values(array_merge(array_values($bestByAgent), $passthrough));
    }

    /**
     * Prefer the newest live leg when the same agent appears on multiple destinations.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    protected function preferFreshestLiveRow(array $a, array $b): array
    {
        $rank = ['incall' => 0, 'queue' => 1, 'ringing' => 2, 'waiting' => 2];
        $ra = $rank[$a['status_group'] ?? ''] ?? 9;
        $rb = $rank[$b['status_group'] ?? ''] ?? 9;

        if ($rb < $ra) {
            return $this->preferRow($b, $a);
        }
        if ($ra < $rb) {
            return $this->preferRow($a, $b);
        }

        // Same status: keep the shorter timer (newer call), not the ghost older leg.
        $ta = (int) ($a['timer_sec'] ?? 0);
        $tb = (int) ($b['timer_sec'] ?? 0);
        if ($tb > 0 && ($ta === 0 || $tb < $ta)) {
            return $this->preferRow($b, $a);
        }

        return $this->preferRow($a, $b);
    }

    protected function legKey(string $stationOrExt, string $destination): string
    {
        $ext = preg_replace('/\D/', '', $stationOrExt) ?? '';
        $dest = preg_replace('/\D/', '', $destination) ?? '';
        if (strlen($dest) > 10) {
            $dest = substr($dest, -10);
        }
        if ($ext === '' && $dest === '') {
            return '';
        }

        return $ext.'|'.$dest;
    }

    /**
     * When Morpheus/agent already knows the destination answered, promote live ringing states.
     */
    protected function refreshConnectedFromHub(): void
    {
        try {
            $api = app(\App\Services\Integrations\ZoomApiService::class);
        } catch (\Throwable) {
            return;
        }

        foreach ($this->callEvents->listLiveStates() as $state) {
            if (! ($state['live'] ?? false)) {
                continue;
            }
            if (($state['destination_answered'] ?? false) || ($state['destination_connected'] ?? false)) {
                continue;
            }

            $uuid = trim((string) ($state['uuid'] ?? ''));
            if ($uuid === '') {
                continue;
            }

            $destination = (string) ($state['destination'] ?? '');

            try {
                $status = $api->hubLiveCallStatus($uuid, $destination !== '' ? $destination : null);
            } catch (\Throwable) {
                continue;
            }

            $connected = ($status['destination_connected'] ?? false) === true
                || ($status['destination_answered'] ?? false) === true
                || (($status['outcome'] ?? '') === 'connected');

            if (! $connected) {
                continue;
            }

            $this->callEvents->markDestinationConnected(
                $uuid,
                $destination !== '' ? $destination : null,
                isset($status['billsec']) ? (int) $status['billsec'] : null,
                (string) ($status['source'] ?? 'monitoring'),
            );
        }
    }

    /**
     * Logged-in agents/team leads who are not currently on a live call.
     *
     * @param  array<int, array<string, mixed>>  $agents
     * @param  array<int, array<string, mixed>>  $liveRows
     * @return array<int, array<string, mixed>>
     */
    protected function buildNotInCallRows(Workspace $workspace, array $agents, array $liveRows): array
    {
        $presence = app(AgentPresenceService::class)->listOnline($workspace);
        if ($presence === []) {
            return [];
        }

        $busyUserIds = [];
        $busyExts = [];
        foreach ($liveRows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid > 0) {
                $busyUserIds[$uid] = true;
            }
            $ext = preg_replace('/\D/', '', (string) ($row['station'] ?? $row['extension'] ?? '')) ?? '';
            if ($ext !== '') {
                $busyExts[$ext] = true;
            }
        }

        $agentsByUser = collect($agents)->keyBy(fn (array $a) => (int) ($a['user_id'] ?? 0));
        $lastEnded = $this->lastCallEndedAtByUser($workspace);

        $rows = [];
        foreach ($presence as $entry) {
            $userId = (int) ($entry['user_id'] ?? 0);
            if ($userId <= 0 || isset($busyUserIds[$userId])) {
                continue;
            }
            // Do not skip stale on_call here — without a live row the agent would vanish.
            // Real live calls are already covered by $busyUserIds above.
            // Agents wrapping up a call belong on the Disposition board, not Not in call.
            if (($entry['in_disposition'] ?? false) === true) {
                continue;
            }
            // Break / lunch agents belong on the Break board.
            $breakStatus = strtolower((string) ($entry['break_status'] ?? 'none'));
            if (in_array($breakStatus, ['break', 'lunch'], true)) {
                continue;
            }

            $agent = $agentsByUser->get($userId) ?: [];
            $role = (string) ($entry['role'] ?? $agent['role'] ?? '');
            $normalized = \App\Support\SalesOps::normalizeLegacyRole($role) ?: $role;

            // Super Admin and other non-agent roles never belong on this board.
            if ($role !== '' && ! AgentPresenceService::isMonitorableRole($role)) {
                continue;
            }
            if ($role !== '' && ! in_array($normalized, AgentPresenceService::MONITOR_ROLES, true)
                && ! in_array($role, AgentPresenceService::MONITOR_ROLES, true)
            ) {
                continue;
            }

            $ext = preg_replace('/\D/', '', (string) ($entry['extension'] ?? $agent['morpheus_extension_num'] ?? '')) ?? '';
            if ($ext !== '' && isset($busyExts[$ext])) {
                continue;
            }

            $idleSince = $entry['idle_since'] ?? null;
            if (! filled($idleSince) && isset($lastEnded[$userId])) {
                $idleSince = $lastEnded[$userId];
            }
            if (! filled($idleSince)) {
                $idleSince = $entry['last_seen_at'] ?? now()->utc()->toIso8601String();
            }

            try {
                $idleSec = max(0, (int) \Carbon\Carbon::parse((string) $idleSince)->diffInSeconds(now()));
            } catch (\Throwable) {
                $idleSec = 0;
            }

            $dialMode = strtolower((string) ($entry['dial_mode'] ?? 'manual')) === 'auto' ? 'auto' : 'manual';
            $dialLabel = $dialMode === 'auto'
                ? (($entry['auto_session_active'] ?? false)
                    ? (($entry['auto_paused'] ?? false) ? 'Auto dial · paused' : 'Auto dial · running')
                    : 'Auto dial')
                : 'Manual dial';

            $rows[] = [
                'id' => 'idle:'.$userId,
                'station' => $ext !== '' ? $ext : '—',
                'extension' => $ext,
                'user' => $this->resolveAgentDisplayName(
                    is_array($agent) ? $agent : null,
                    ['name' => $entry['name'] ?? null],
                    $ext
                ),
                'user_id' => $userId,
                'role_label' => (string) ($entry['role_label'] ?? $agent['role_label'] ?? \App\Support\SalesOps::roleLabel($role)),
                'status' => 'NOT IN CALL',
                'status_group' => 'idle',
                'bucket' => 'not_in_call',
                'timer_sec' => $idleSec,
                'timer_label' => $this->formatTimer($idleSec),
                'campaign' => '—',
                'destination' => $dialLabel,
                'dial_mode' => $dialMode,
                'dial_mode_label' => $dialLabel,
                'direction' => '',
                'color' => 'idle',
                'under_two_minutes' => false,
                'connected_at' => null,
                'idle_since' => $idleSince,
            ];
        }

        usort($rows, static function (array $a, array $b) {
            $name = strcasecmp((string) ($a['user'] ?? ''), (string) ($b['user'] ?? ''));
            if ($name !== 0) {
                return $name;
            }

            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });

        return $rows;
    }

    /**
     * Monitorable agents with an extension who are not currently present (logged out / offline).
     *
     * @param  array<int, array<string, mixed>>  $agents
     * @param  array<int, array<string, mixed>>  $liveRows
     * @param  array<int, array<string, mixed>>  $notInCallRows
     * @param  array<int, array<string, mixed>>  $dispositionRows
     * @return array<int, array<string, mixed>>
     */
    protected function buildNotLoggedInRows(
        Workspace $workspace,
        array $agents,
        array $liveRows,
        array $notInCallRows = [],
        array $dispositionRows = []
    ): array {
        $presence = app(AgentPresenceService::class)->listOnline($workspace);
        $onlineUserIds = [];
        $onlineExts = [];
        foreach ($presence as $entry) {
            $uid = (int) ($entry['user_id'] ?? 0);
            if ($uid > 0) {
                $onlineUserIds[$uid] = true;
            }
            $ext = preg_replace('/\D/', '', (string) ($entry['extension'] ?? '')) ?? '';
            if ($ext !== '') {
                $onlineExts[$ext] = true;
            }
        }

        $occupiedUserIds = $onlineUserIds;
        $occupiedExts = $onlineExts;
        foreach (array_merge($liveRows, $notInCallRows, $dispositionRows) as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid > 0) {
                $occupiedUserIds[$uid] = true;
            }
            $ext = preg_replace('/\D/', '', (string) ($row['station'] ?? $row['extension'] ?? '')) ?? '';
            if ($ext !== '') {
                $occupiedExts[$ext] = true;
            }
        }

        $rows = [];
        foreach ($agents as $agent) {
            if (! is_array($agent)) {
                continue;
            }

            $role = (string) ($agent['role'] ?? '');
            $label = (string) ($agent['role_label'] ?? '');
            if (AgentPresenceService::isExcludedFromMonitoring($role, $label)) {
                continue;
            }
            if (! AgentPresenceService::isMonitorableRole($role)) {
                continue;
            }

            $userId = (int) ($agent['user_id'] ?? 0);
            $ext = preg_replace('/\D/', '', (string) ($agent['morpheus_extension_num'] ?? '')) ?? '';
            if ($userId > 0 && isset($occupiedUserIds[$userId])) {
                continue;
            }
            if ($ext !== '' && isset($occupiedExts[$ext])) {
                continue;
            }

            $rows[] = [
                'id' => 'offline:'.($userId > 0 ? $userId : ($ext !== '' ? $ext : md5(json_encode($agent)))),
                'station' => $ext !== '' ? $ext : '—',
                'extension' => $ext,
                'user' => $this->resolveAgentDisplayName(is_array($agent) ? $agent : null, null, $ext !== '' ? $ext : null),
                'user_id' => $userId,
                'role_label' => $label !== '' ? $label : \App\Support\SalesOps::roleLabel($role),
                'status' => 'NOT LOGGED IN',
                'status_group' => 'not_logged_in',
                'bucket' => 'not_logged_in',
                'timer_sec' => 0,
                'timer_label' => '00:00',
                'campaign' => '—',
                'destination' => '—',
                'dial_mode' => '',
                'dial_mode_label' => '',
                'direction' => '',
                'color' => 'offline',
                'under_two_minutes' => false,
                'connected_at' => null,
                'idle_since' => null,
            ];
        }

        usort($rows, static fn (array $a, array $b) => strcasecmp((string) ($a['user'] ?? ''), (string) ($b['user'] ?? '')));

        return $rows;
    }

    /**
     * Agents currently on the call-summary / disposition modal.
     *
     * @param  array<int, array<string, mixed>>  $agents
     * @param  array<int, array<string, mixed>>  $liveRows
     * @return array<int, array<string, mixed>>
     */
    protected function buildDispositionRows(Workspace $workspace, array $agents, array $liveRows): array
    {
        $presence = app(AgentPresenceService::class)->listOnline($workspace);
        if ($presence === []) {
            return [];
        }

        $busyUserIds = [];
        foreach ($liveRows as $row) {
            if (in_array(($row['status_group'] ?? ''), ['incall', 'ringing', 'waiting', 'queue'], true)) {
                $uid = (int) ($row['user_id'] ?? 0);
                if ($uid > 0) {
                    $busyUserIds[$uid] = true;
                }
            }
        }

        $agentsByUser = collect($agents)->keyBy(fn (array $a) => (int) ($a['user_id'] ?? 0));
        $rows = [];

        foreach ($presence as $entry) {
            if (($entry['in_disposition'] ?? false) !== true) {
                continue;
            }

            $breakStatus = strtolower((string) ($entry['break_status'] ?? 'none'));
            if (in_array($breakStatus, ['break', 'lunch'], true)) {
                continue;
            }

            $userId = (int) ($entry['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            // Still live-talking overrides wrap-up state (matched live row only).
            if (isset($busyUserIds[$userId])) {
                continue;
            }

            $agent = $agentsByUser->get($userId) ?: [];
            $role = (string) ($entry['role'] ?? $agent['role'] ?? '');
            if ($role !== '' && ! AgentPresenceService::isMonitorableRole($role)) {
                continue;
            }

            $ext = preg_replace('/\D/', '', (string) ($entry['extension'] ?? $agent['morpheus_extension_num'] ?? '')) ?? '';
            $since = $entry['disposition_since'] ?? $entry['idle_since'] ?? $entry['last_seen_at'] ?? now()->utc()->toIso8601String();
            try {
                $timerSec = max(0, (int) \Carbon\Carbon::parse((string) $since)->diffInSeconds(now()));
            } catch (\Throwable) {
                $timerSec = 0;
            }

            $destination = trim((string) ($entry['disposition_phone'] ?? $entry['last_destination'] ?? ''));
            $dialMode = strtolower((string) ($entry['dial_mode'] ?? 'manual')) === 'auto' ? 'auto' : 'manual';

            $rows[] = [
                'id' => 'disposition:'.$userId,
                'station' => $ext !== '' ? $ext : '—',
                'extension' => $ext,
                'user' => $this->resolveAgentDisplayName(
                    is_array($agent) ? $agent : null,
                    ['name' => $entry['name'] ?? null],
                    $ext
                ),
                'user_id' => $userId,
                'role_label' => (string) ($entry['role_label'] ?? $agent['role_label'] ?? \App\Support\SalesOps::roleLabel($role)),
                'status' => 'DISPOSITION',
                'status_group' => 'disposition',
                'bucket' => 'disposition',
                'timer_sec' => $timerSec,
                'timer_label' => $this->formatTimer($timerSec),
                'campaign' => $dialMode === 'auto' ? 'Auto dial' : 'Manual dial',
                'destination' => $destination !== '' ? $destination : 'Awaiting disposition',
                'dial_mode' => $dialMode,
                'dial_mode_label' => $dialMode === 'auto' ? 'Auto dial' : 'Manual dial',
                'direction' => '',
                'color' => 'disposition',
                'under_two_minutes' => false,
                'connected_at' => null,
                'idle_since' => $since,
            ];
        }

        usort($rows, static fn (array $a, array $b) => ($b['timer_sec'] ?? 0) <=> ($a['timer_sec'] ?? 0));

        return $rows;
    }

    /**
     * Agents currently on Break (5m) or Lunch (30m) — DB-backed.
     *
     * @param  array<int, array<string, mixed>>  $agents
     * @param  array<int, array<string, mixed>>  $liveRows
     * @return array<int, array<string, mixed>>
     */
    protected function buildBreakLunchRows(Workspace $workspace, array $agents, array $liveRows): array
    {
        $sessions = app(AgentBreakService::class)->activeSessions($workspace);
        if ($sessions === []) {
            return [];
        }

        $busyUserIds = [];
        foreach ($liveRows as $row) {
            if (in_array(($row['status_group'] ?? ''), ['incall', 'ringing', 'waiting', 'queue'], true)) {
                $uid = (int) ($row['user_id'] ?? 0);
                if ($uid > 0) {
                    $busyUserIds[$uid] = true;
                }
            }
        }

        $agentsByUser = collect($agents)->keyBy(fn (array $a) => (int) ($a['user_id'] ?? 0));
        $presenceByUser = collect(app(AgentPresenceService::class)->listOnline($workspace))
            ->keyBy(fn (array $e) => (int) ($e['user_id'] ?? 0));

        $rows = [];
        foreach ($sessions as $session) {
            $userId = (int) $session->user_id;
            if ($userId <= 0 || isset($busyUserIds[$userId])) {
                continue;
            }

            $agent = $agentsByUser->get($userId) ?: [];
            $presence = $presenceByUser->get($userId) ?: [];
            $role = (string) ($presence['role'] ?? $agent['role'] ?? '');
            if ($role !== '' && ! AgentPresenceService::isMonitorableRole($role)) {
                continue;
            }

            $ext = preg_replace('/\D/', '', (string) ($presence['extension'] ?? $agent['morpheus_extension_num'] ?? '')) ?? '';
            $startedAt = $session->started_at?->utc()?->toIso8601String() ?? now()->utc()->toIso8601String();
            $endsAt = $session->ends_at?->utc()?->toIso8601String();
            $timerSec = max(0, (int) ($session->started_at ? now()->getTimestamp() - $session->started_at->getTimestamp() : 0));
            $remaining = max(0, (int) ($session->ends_at ? $session->ends_at->getTimestamp() - time() : 0));
            $isLunch = $session->type === \App\Models\AgentActivitySession::TYPE_LUNCH;
            $label = $isLunch ? 'LUNCH' : 'BREAK';
            $bucket = $isLunch ? 'lunch' : 'break';

            $rows[] = [
                'id' => $bucket.':'.$userId,
                'station' => $ext !== '' ? $ext : '—',
                'extension' => $ext,
                'user' => $this->resolveAgentDisplayName(
                    is_array($agent) ? $agent : null,
                    [
                        'name' => $session->user?->name ?? ($presence['name'] ?? null),
                    ],
                    $ext
                ),
                'user_id' => $userId,
                'role_label' => (string) ($presence['role_label'] ?? $agent['role_label'] ?? \App\Support\SalesOps::roleLabel($role)),
                'status' => $label,
                'status_group' => $bucket,
                'bucket' => $bucket,
                'timer_sec' => $timerSec,
                'timer_label' => $this->formatTimer($timerSec),
                'remaining_sec' => $remaining,
                'campaign' => '—',
                'destination' => $remaining > 0
                    ? (($isLunch ? 'Lunch · 30 min' : 'Break · 5 min').' · Ends in '.$this->formatTimer($remaining))
                    : (($isLunch ? 'Lunch' : 'Break').' · Ending…'),
                'dial_mode' => strtolower((string) ($presence['dial_mode'] ?? 'manual')) === 'auto' ? 'auto' : 'manual',
                'dial_mode_label' => $isLunch ? 'Lunch' : 'Break',
                'direction' => '',
                'color' => $bucket,
                'under_two_minutes' => false,
                'connected_at' => null,
                'idle_since' => $startedAt,
                'break_ends_at' => $endsAt,
            ];
        }

        usort($rows, static fn (array $a, array $b) => ($b['timer_sec'] ?? 0) <=> ($a['timer_sec'] ?? 0));

        return $rows;
    }

    /**
     * Recently ended legs that never connected (dead / missed / busy).
     *
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $agentsByExt
     * @return array<int, array<string, mixed>>
     */
    protected function recentDeadCallRows(Workspace $workspace, $agentsByExt): array
    {
        $logs = CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('updated_at', '>=', now()->subSeconds(120))
            ->where(function ($query) {
                $query->whereIn('status', ['no_answer', 'busy', 'failed', 'missed', 'cancelled', 'canceled'])
                    ->orWhere(function ($inner) {
                        $inner->where('status', 'completed')
                            ->where(function ($dur) {
                                $dur->whereNull('duration_sec')->orWhere('duration_sec', '<=', 0);
                            });
                    });
            })
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        $rows = [];
        $seen = [];
        foreach ($logs as $log) {
            $ext = preg_replace('/\D/', '', (string) ($log->from_extension ?? '')) ?? '';
            $dest = preg_replace('/\D/', '', (string) ($log->to_phone ?? '')) ?? '';
            $legKey = $ext.'|'.$dest;
            if ($legKey !== '|' && isset($seen[$legKey])) {
                continue;
            }
            if ($legKey !== '|') {
                $seen[$legKey] = true;
            }

            $uuid = (string) ($log->morpheus_call_uuid ?: ('dead-local:'.$log->id));
            $row = $this->buildRow([
                'uuid' => $uuid,
                'state' => 'DEAD',
                'answered' => false,
                'billsec' => 0,
                'age_sec' => max(0, $log->updated_at?->diffInSeconds(now()) ?? 0),
                'destination' => (string) ($log->to_phone ?? ''),
                'caller' => '',
                'extension' => $ext,
                'campaign' => '—',
                'direction' => (string) ($log->direction ?? ''),
                'raw' => [],
            ], $agentsByExt, [
                $uuid => [
                    'user_id' => $log->user_id,
                    'agent_name' => $log->user?->name,
                    'direction' => $log->direction,
                    'from_extension' => $log->from_extension,
                ],
            ]);

            if ($row !== null) {
                $status = match (strtolower((string) $log->status)) {
                    'busy' => 'DEAD · BUSY',
                    'no_answer', 'missed' => 'DEAD · NO ANSWER',
                    'failed' => 'DEAD · FAILED',
                    'cancelled', 'canceled' => 'DEAD · CANCELLED',
                    default => 'DEAD',
                };
                $row['status'] = $status;
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function attachDialModeToLiveRows(?Workspace $workspace, array $rows): array
    {
        if (! $workspace || $rows === []) {
            return $rows;
        }

        $byUser = [];
        foreach (app(AgentPresenceService::class)->listOnline($workspace) as $entry) {
            $byUser[(int) ($entry['user_id'] ?? 0)] = $entry;
        }

        foreach ($rows as &$row) {
            $uid = (int) ($row['user_id'] ?? 0);
            $presence = $uid > 0 ? ($byUser[$uid] ?? null) : null;
            if (! is_array($presence)) {
                continue;
            }
            $dialMode = strtolower((string) ($presence['dial_mode'] ?? 'manual')) === 'auto' ? 'auto' : 'manual';
            $row['dial_mode'] = $dialMode;
            $row['dial_mode_label'] = $dialMode === 'auto' ? 'Auto dial' : 'Manual dial';
            if (($row['campaign'] ?? '—') === '—' || $row['campaign'] === '') {
                $row['campaign'] = $row['dial_mode_label'];
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    protected function lastCallEndedAtByUser(Workspace $workspace): array
    {
        $logs = CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('user_id')
            ->where('created_at', '>=', now()->subDay())
            ->whereIn('status', ['completed', 'ended', 'no-answer', 'busy', 'failed', 'canceled', 'cancelled'])
            ->orderByDesc('id')
            ->limit(300)
            ->get(['user_id', 'updated_at', 'ended_at']);

        $out = [];
        foreach ($logs as $log) {
            $uid = (int) $log->user_id;
            if ($uid <= 0 || isset($out[$uid])) {
                continue;
            }
            $stamp = $log->ended_at ?? $log->updated_at;
            if ($stamp) {
                $out[$uid] = \Carbon\Carbon::parse($stamp)->utc()->toIso8601String();
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function recentLocalLogsByUuid(?Workspace $workspace): array
    {
        if (! $workspace) {
            return [];
        }

        return CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('created_at', '>=', now()->subHours(6))
            ->whereNotNull('morpheus_call_uuid')
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->mapWithKeys(function (CommunicationCallLog $log) {
                return [
                    (string) $log->morpheus_call_uuid => [
                        'user_id' => $log->user_id,
                        'agent_name' => $log->user?->name,
                        'direction' => $log->direction,
                        'from_extension' => $log->from_extension,
                    ],
                ];
            })
            ->all();
    }

    protected function formatTimer(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $minutes = intdiv($seconds, 60);
        $remain = $seconds % 60;

        return sprintf('%02d:%02d', $minutes, $remain);
    }
}
