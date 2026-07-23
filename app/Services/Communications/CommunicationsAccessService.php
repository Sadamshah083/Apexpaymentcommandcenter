<?php

namespace App\Services\Communications;

use App\Models\CommunicationCallLog;
use App\Models\User;
use App\Support\SalesOps;
use Illuminate\Http\Request;

class CommunicationsAccessService
{
    /** Channels shown first in the hub nav (remaining channels appear under “More” on small screens). */
    public const PRIMARY_NAV_CHANNELS = [
        'inbox',
        'calls',
        'sms',
        'voicemail',
        'recordings',
        'chat',
    ];

    /** Core agent operations: dial, inbox, call history, media. */
    public const AGENT_CHANNELS = [
        'inbox',
        'calls',
        'recordings',
        'voicemail',
        'sms',
        'chat',
    ];

    /** Team leads see their team's presence and quick-dial links. */
    public const TEAM_LEAD_CHANNELS = [
        'team',
    ];

    /** Managers / supervisors: operational telephony & dialer CRM (read + dial, no provisioning). */
    public const SUPERVISOR_CHANNELS = [
        'queues',
        'conferences',
        'leads',
        'campaigns',
        'lists',
    ];

    /** Full telephony administration (provision extensions, agents, settings). */
    public const ADMIN_ONLY_CHANNELS = [
        'extensions',
        'agents',
    ];

    public const ADMIN_ONLY_PANELS = [
        'settings',
        'agents',
        'extensions',
    ];

  /**
   * Access tier for Communications Hub UI and mutations.
   *
   * admin      — super_admin / admin (admin portal): full hub + telephony config
   * supervisor — manager (admin portal): dial + ops channels, no provisioning
   * team_lead  — setter/closer team leads (portal): agents + team visibility
   * qa         — B2B Closer Team QA: call notes + live monitoring
   * agent      — setters / closers (portal): dial + own call recordings only
   */
    public function tierFor(?User $user, string $routePrefix): string
    {
        if ($user === null) {
            return 'guest';
        }

        if ($routePrefix === 'admin.') {
            if ($user->isSuperAdmin() || $user->isAdmin()) {
                return 'admin';
            }

            if ($user->isManager()) {
                return 'supervisor';
            }

            return $user->canAccessAdminPortal() ? 'supervisor' : 'guest';
        }

        if (! $user->canAccessPortal()) {
            return 'guest';
        }

        $role = $user->getWorkspaceRole();

        if (SalesOps::isQaRole($role)) {
            return 'qa';
        }

        if (in_array($role, ['appointment_setter_team_lead', 'closers_team_lead'], true)) {
            return 'team_lead';
        }

        return 'agent';
    }

    public function isAgentTier(?User $user, string $routePrefix): bool
    {
        return in_array($this->tierFor($user, $routePrefix), ['agent', 'team_lead'], true);
    }

    public function canConfigure(?User $user, string $routePrefix): bool
    {
        return $this->tierFor($user, $routePrefix) === 'admin';
    }

    public function canManageTelephony(?User $user, string $routePrefix): bool
    {
        return $this->canConfigure($user, $routePrefix);
    }

    public function canDial(?User $user, string $routePrefix): bool
    {
        return in_array($this->tierFor($user, $routePrefix), ['admin', 'supervisor', 'team_lead', 'agent'], true);
    }

    /**
     * Auto-dial + imported leads queue: admins, managers, team leads, and agents.
     */
    public function canAutoDial(?User $user, string $routePrefix): bool
    {
        return in_array($this->tierFor($user, $routePrefix), ['admin', 'supervisor', 'team_lead', 'agent'], true);
    }

    /**
     * Browse agent vs team-lead call recordings in the dialer workspace.
     */
    public function canViewTeamRecordings(?User $user, string $routePrefix): bool
    {
        return in_array($this->tierFor($user, $routePrefix), ['admin', 'supervisor', 'team_lead', 'qa'], true);
    }

    /**
     * Call Notes page (agent picker for supervisors / QA / team leads; own notes for agents).
     */
    public function canViewCallNotes(?User $user, string $routePrefix): bool
    {
        return in_array($this->tierFor($user, $routePrefix), ['admin', 'supervisor', 'team_lead', 'qa', 'agent'], true);
    }

    /**
     * Live call wallboard (active / waiting counts + timers).
     */
    public function canViewCallMonitoring(?User $user, string $routePrefix): bool
    {
        return in_array($this->tierFor($user, $routePrefix), ['admin', 'supervisor', 'team_lead', 'qa'], true);
    }

    /**
     * All call logs — agents see only their own; team leads their team;
     * QA / managers / admins can review broader sets.
     */
    public function canViewAllCallLogs(?User $user, string $routePrefix): bool
    {
        return in_array($this->tierFor($user, $routePrefix), ['admin', 'supervisor', 'team_lead', 'qa', 'agent'], true);
    }

    /**
     * Play / download a recording. Agents may only open their own call recordings.
     * Team leads may open recordings for their team members.
     */
    public function canAccessRecording(
        ?User $user,
        string $routePrefix,
        string $recordingId,
        ?string $callReferenceId = null,
    ): bool {
        if ($user === null || $recordingId === '') {
            return false;
        }

        $tier = $this->tierFor($user, $routePrefix);

        if (in_array($tier, ['admin', 'supervisor', 'qa'], true)) {
            return true;
        }

        $workspace = app(\App\Services\Workspace\WorkspaceContextService::class)
            ->resolveActiveWorkspace($user);
        if (! $workspace) {
            return false;
        }

        $query = CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($inner) use ($recordingId, $callReferenceId) {
                $inner->where('recording_file_id', $recordingId);
                if (filled($callReferenceId)) {
                    $ref = (string) $callReferenceId;
                    $inner->orWhere('morpheus_call_uuid', $ref);
                    if (str_starts_with($ref, 'local:')) {
                        $inner->orWhere('id', (int) substr($ref, 6));
                    } elseif (ctype_digit($ref)) {
                        $inner->orWhere('id', (int) $ref);
                    }
                }
            });

        $log = $query->first();
        if (! $log) {
            return false;
        }

        $ownerId = (int) ($log->user_id ?? 0);
        if ($ownerId <= 0) {
            return false;
        }

        if ($tier === 'agent') {
            return $ownerId === (int) $user->id;
        }

        if ($tier === 'team_lead') {
            $allowedIds = app(CallNotesHistoryService::class)
                ->dialerAgents($workspace, $user, 'team_lead')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            return in_array($ownerId, $allowedIds, true);
        }

        return false;
    }

    public function canAccessHub(?User $user, string $routePrefix): bool
    {
        if ($routePrefix === 'portal.') {
            return $user !== null && $user->canAccessPortal();
        }

        return $user !== null && $user->canAccessAdminPortal();
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public function channelsFor(?User $user, string $routePrefix): array
    {
        $tier = $this->tierFor($user, $routePrefix);

        $allowed = match ($tier) {
            'admin' => array_keys(CommunicationsInboxService::CHANNELS),
            'supervisor' => array_merge(self::AGENT_CHANNELS, self::TEAM_LEAD_CHANNELS, self::SUPERVISOR_CHANNELS),
            'team_lead' => array_merge(self::AGENT_CHANNELS, self::TEAM_LEAD_CHANNELS),
            'qa' => ['recordings'],
            'agent' => self::AGENT_CHANNELS,
            default => [],
        };

        return collect(CommunicationsInboxService::CHANNELS)
            ->only($allowed)
            ->all();
    }

    public function canAccessChannel(?User $user, string $routePrefix, string $channel): bool
    {
        return array_key_exists($channel, $this->channelsFor($user, $routePrefix));
    }

    public function canAccessPanel(?User $user, string $routePrefix, string $panel): bool
    {
        if (in_array($panel, self::ADMIN_ONLY_PANELS, true)) {
            return $this->canConfigure($user, $routePrefix);
        }

        if (in_array($panel, ['team', 'queues', 'conferences', 'leads', 'campaigns', 'lists'], true)) {
            return $this->tierFor($user, $routePrefix) !== 'guest'
                && ! in_array($this->tierFor($user, $routePrefix), ['agent', 'qa'], true);
        }

        return $this->canAccessHub($user, $routePrefix);
    }

    /**
     * @return array{channel: string, panel: string, tier: string, can_configure: bool}
     */
    public function clampScope(
        Request $request,
        string $routePrefix,
        ?User $user,
        string $channel,
        string $panel,
    ): array {
        $tier = $this->tierFor($user, $routePrefix);

        if (! $this->canAccessChannel($user, $routePrefix, $channel)) {
            $channel = $tier === 'qa' ? 'recordings' : 'inbox';
            $panel = 'empty';
        }

        if (! $this->canAccessPanel($user, $routePrefix, $panel)) {
            $panel = 'dialer';
        }

        if ($request->get('panel') === 'settings' && ! $this->canConfigure($user, $routePrefix)) {
            $panel = 'dialer';
        }

        if (
            in_array($tier, ['agent', 'team_lead', 'supervisor'], true)
            && $panel === 'empty'
            && ! $request->has('panel')
            && ! $request->filled('contact')
            && ! $request->filled('call')
            && ! $request->filled('session')
            && ! $request->filled('voicemail')
            && ! $request->filled('recording')
        ) {
            $panel = 'dialer';
        }

        return [
            'channel' => $channel,
            'panel' => $panel,
            'tier' => $tier,
            'can_configure' => $this->canConfigure($user, $routePrefix),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function viewMeta(?User $user, string $routePrefix): array
    {
        $tier = $this->tierFor($user, $routePrefix);

        return [
            'tier' => $tier,
            'canConfigure' => $this->canConfigure($user, $routePrefix),
            'canManageTelephony' => $this->canManageTelephony($user, $routePrefix),
            'canDial' => $this->canDial($user, $routePrefix),
            'canAutoDial' => $this->canAutoDial($user, $routePrefix),
            'canViewTeamRecordings' => $this->canViewTeamRecordings($user, $routePrefix),
            'canViewCallNotes' => $this->canViewCallNotes($user, $routePrefix),
            'canViewCallMonitoring' => $this->canViewCallMonitoring($user, $routePrefix),
            'canViewAllCallLogs' => $this->canViewAllCallLogs($user, $routePrefix),
            'canUseBreakLunch' => $this->isAgentTier($user, $routePrefix),
            'roleLabel' => $this->roleLabel($tier),
        ];
    }

    protected function roleLabel(string $tier): string
    {
        return match ($tier) {
            'admin' => 'Administrator',
            'supervisor' => 'Manager',
            'team_lead' => 'Team lead',
            'qa' => 'QA',
            'agent' => 'Agent',
            default => 'User',
        };
    }
}
