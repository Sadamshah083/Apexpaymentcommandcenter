<?php

namespace App\Services\Communications;

use App\Models\User;
use App\Models\Workspace;
use App\Support\SalesOps;
use Illuminate\Support\Facades\Cache;

class AgentPresenceService
{
    public const TTL_SECONDS = 180;

    public const MONITOR_ROLES = [
        'appointment_setter',
        'closers',
        'closer',
    ];

    /** Appointment-setter agents only (never team leads). */
    public const SETTER_MONITOR_ROLES = [
        'appointment_setter',
    ];

    /** Closer agents only (never team leads). */
    public const CLOSER_MONITOR_ROLES = [
        'closer',
        'closers',
    ];

    /** Never show these accounts on Call Monitoring. */
    public const EXCLUDED_ROLES = [
        'super_admin',
        'admin',
        'manager',
        'closers_qa',
        'appointment_setter_team_lead',
        'closers_team_lead',
    ];

    public static function isMonitorableRole(?string $role): bool
    {
        $normalized = \App\Support\SalesOps::normalizeLegacyRole($role) ?: (string) $role;
        if ($normalized === '' || self::isExcludedRole($normalized) || SalesOps::isTeamLeadRole($normalized)) {
            return false;
        }

        return in_array($normalized, self::MONITOR_ROLES, true)
            || in_array((string) $role, self::MONITOR_ROLES, true);
    }

    public static function isExcludedRole(?string $role): bool
    {
        $normalized = \App\Support\SalesOps::normalizeLegacyRole((string) $role) ?: (string) $role;

        return in_array($normalized, self::EXCLUDED_ROLES, true)
            || in_array((string) $role, self::EXCLUDED_ROLES, true)
            || SalesOps::isTeamLeadRole($normalized);
    }

    /**
     * Role keys the wallboard may show for this viewer.
     * null = all monitorable agent roles (admin/supervisor).
     *
     * @return list<string>|null
     */
    public static function monitorRolesForViewer(?User $viewer, ?int $workspaceId): ?array
    {
        if (! $viewer || ! $workspaceId) {
            return null;
        }

        $role = (string) ($viewer->getWorkspaceRole($workspaceId) ?? '');
        $normalized = SalesOps::normalizeLegacyRole($role) ?: $role;

        if (SalesOps::isSetterTeamLeadRole($normalized)) {
            return self::SETTER_MONITOR_ROLES;
        }

        if (SalesOps::isClosersTeamLeadRole($normalized) || SalesOps::isQaRole($normalized)) {
            return self::CLOSER_MONITOR_ROLES;
        }

        // Admins / managers see both families (agents only — TLs already excluded).
        return null;
    }

    /**
     * @param  list<string>|null  $allowedRoles
     */
    public static function roleAllowedOnBoard(?string $role, ?array $allowedRoles): bool
    {
        if (! self::isMonitorableRole($role)) {
            return false;
        }

        if ($allowedRoles === null) {
            return true;
        }

        $normalized = SalesOps::normalizeLegacyRole((string) $role) ?: (string) $role;

        return in_array($normalized, $allowedRoles, true)
            || in_array((string) $role, $allowedRoles, true);
    }

    /**
     * True when this account must never appear on Call Monitoring (live, idle, disposition).
     */
    public static function isExcludedFromMonitoring(
        ?string $role = null,
        ?string $roleLabel = null,
        ?User $user = null,
        ?int $workspaceId = null,
        ?string $displayName = null
    ): bool {
        if ($user) {
            if ($user->isSuperAdmin($workspaceId)
                || $user->isAdmin($workspaceId)
                || $user->isManager($workspaceId)
                || $user->isPlatformSuperAdmin()
                || ($workspaceId && $user->canAccessAdminPortal($workspaceId))
            ) {
                return true;
            }

            if (self::looksLikeAdminIdentity((string) $user->name)
                || self::looksLikeAdminIdentity((string) $user->email)
            ) {
                return true;
            }
        }

        if (SalesOps::isAdminPortalRole($role) || self::isExcludedRole($role) || SalesOps::isTeamLeadRole($role)) {
            return true;
        }

        $label = strtolower(trim((string) $roleLabel));
        if (in_array($label, ['super admin', 'admin', 'manager'], true)) {
            return true;
        }

        // Team lead labels must never appear even if role key is missing/stale.
        if (str_contains($label, 'team lead')) {
            return true;
        }

        if (self::looksLikeAdminIdentity($displayName)
            || self::looksLikeAdminIdentity((string) $roleLabel)
        ) {
            return true;
        }

        // Non-agent portal roles stay off the board when a role is known.
        if (filled($role) && ! self::isMonitorableRole($role)) {
            return true;
        }

        return false;
    }

    /**
     * Login / display names that must never appear on the wallboard (e.g. user "admin").
     */
    public static function looksLikeAdminIdentity(?string $value): bool
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return false;
        }

        if (str_contains($raw, '@')) {
            $raw = strstr($raw, '@', true) ?: $raw;
        }

        $normalized = preg_replace('/[^a-z0-9]+/', '', $raw) ?? '';

        return in_array($normalized, [
            'admin',
            'superadmin',
            'administrator',
            'root',
        ], true);
    }

    /**
     * @param  array{
     *   dial_mode?: string,
     *   auto_session_active?: bool,
     *   auto_paused?: bool,
     *   on_call?: bool,
     *   in_disposition?: bool,
     *   disposition_phone?: ?string,
     *   extension?: ?string,
     *   role?: ?string,
     *   role_label?: ?string,
     *   name?: ?string
     * }  $payload
     * @return array<string, mixed>
     */
    public function heartbeat(User $user, Workspace $workspace, array $payload = []): array
    {
        $role = (string) ($payload['role'] ?? '');
        // Super Admin / Admin / non-agent accounts must not appear on the wallboard.
        if (self::isExcludedFromMonitoring($role, (string) ($payload['role_label'] ?? ''), $user, (int) $workspace->id)) {
            $this->forgetUser($workspace, (int) $user->id);

            return [
                'user_id' => (int) $user->id,
                'workspace_id' => (int) $workspace->id,
                'ignored' => true,
                'role' => $role,
            ];
        }

        $map = $this->readMap((int) $workspace->id);
        $existing = is_array($map[$user->id] ?? null) ? $map[$user->id] : [];

        $dialMode = strtolower(trim((string) ($payload['dial_mode'] ?? $existing['dial_mode'] ?? 'manual')));
        if (! in_array($dialMode, ['auto', 'manual'], true)) {
            $dialMode = 'manual';
        }

        $onCall = (bool) ($payload['on_call'] ?? false);
        $inDisposition = array_key_exists('in_disposition', $payload)
            ? (bool) $payload['in_disposition']
            : (bool) ($existing['in_disposition'] ?? false);
        if ($onCall) {
            $inDisposition = false;
        }

        $breakStatus = strtolower(trim((string) ($payload['break_status'] ?? $existing['break_status'] ?? 'none')));
        if (! in_array($breakStatus, ['none', 'break', 'lunch'], true)) {
            $breakStatus = 'none';
        }
        // Live call clears break/lunch immediately.
        if ($onCall) {
            $breakStatus = 'none';
        }

        $now = now()->utc();
        $nowIso = $now->toIso8601String();
        $breakEndsAt = null;
        $breakSince = null;
        if ($breakStatus === 'break' || $breakStatus === 'lunch') {
            $breakSince = $existing['break_since'] ?? $nowIso;
            if (($existing['break_status'] ?? '') !== $breakStatus) {
                $breakSince = $nowIso;
            }
            if (array_key_exists('break_ends_at', $payload) && filled($payload['break_ends_at'])) {
                $breakEndsAt = (string) $payload['break_ends_at'];
            } elseif (($existing['break_status'] ?? '') === $breakStatus && filled($existing['break_ends_at'] ?? null)) {
                $breakEndsAt = (string) $existing['break_ends_at'];
            } else {
                $minutes = $breakStatus === 'lunch' ? 30 : 5;
                $breakEndsAt = $now->copy()->addMinutes($minutes)->toIso8601String();
            }

            // Auto-expire on heartbeat if the timer already elapsed.
            try {
                if (\Carbon\Carbon::parse($breakEndsAt)->lte($now)) {
                    $breakStatus = 'none';
                    $breakEndsAt = null;
                    $breakSince = null;
                }
            } catch (\Throwable) {
                $breakStatus = 'none';
                $breakEndsAt = null;
                $breakSince = null;
            }
        }

        // Break/lunch takes the agent off disposition + not-in-call availability.
        if ($breakStatus !== 'none') {
            $inDisposition = false;
        }

        $idleSince = $existing['idle_since'] ?? $nowIso;
        if ($onCall || $breakStatus !== 'none') {
            $idleSince = null;
        } elseif (! filled($idleSince)) {
            $idleSince = $nowIso;
        }

        $dispositionSince = $existing['disposition_since'] ?? null;
        if ($inDisposition) {
            $dispositionSince = $dispositionSince ?: $nowIso;
        } else {
            $dispositionSince = null;
        }

        $dispositionPhone = null;
        if ($inDisposition) {
            $dispositionPhone = preg_replace('/\D/', '', (string) ($payload['disposition_phone'] ?? $existing['disposition_phone'] ?? '')) ?: null;
        }

        $entry = [
            'user_id' => (int) $user->id,
            'workspace_id' => (int) $workspace->id,
            // Always prefer the live User record so renames show on Call Monitoring immediately.
            'name' => trim((string) $user->name) !== ''
                ? (string) $user->name
                : (string) ($payload['name'] ?? $existing['name'] ?? 'Agent'),
            'extension' => preg_replace('/\D/', '', (string) ($payload['extension'] ?? $existing['extension'] ?? '')) ?: null,
            'role' => (string) ($payload['role'] ?? $existing['role'] ?? ''),
            'role_label' => (string) ($payload['role_label'] ?? $existing['role_label'] ?? SalesOps::roleLabel($payload['role'] ?? $existing['role'] ?? null)),
            'dial_mode' => $dialMode,
            'dial_mode_label' => $dialMode === 'auto' ? 'Auto dial' : 'Manual dial',
            'auto_session_active' => (bool) ($payload['auto_session_active'] ?? false),
            'auto_paused' => (bool) ($payload['auto_paused'] ?? false),
            'on_call' => $onCall,
            'in_disposition' => $inDisposition,
            'disposition_since' => $dispositionSince,
            'disposition_phone' => $dispositionPhone,
            'break_status' => $breakStatus,
            'break_since' => $breakSince,
            'break_ends_at' => $breakEndsAt,
            'last_seen_at' => $nowIso,
            'idle_since' => $idleSince,
        ];

        $map[$user->id] = $entry;
        $this->writeMap((int) $workspace->id, $map);

        return $entry;
    }

    public function forgetUser(Workspace $workspace, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $map = $this->readMap((int) $workspace->id);
        if (! isset($map[$userId])) {
            return;
        }

        unset($map[$userId]);
        $this->writeMap((int) $workspace->id, $map);
    }

    public function presenceVersion(Workspace|int $workspace): int
    {
        $workspaceId = $workspace instanceof Workspace ? (int) $workspace->id : (int) $workspace;

        return (int) Cache::get($this->versionKey($workspaceId), 0);
    }

    /**
     * Mark agent idle after hangup so the Not-in-call timer restarts cleanly.
     */
    public function markCallEnded(User $user, Workspace $workspace): void
    {
        $map = $this->readMap((int) $workspace->id);
        $existing = is_array($map[$user->id] ?? null) ? $map[$user->id] : [];
        if ($existing === []) {
            return;
        }

        $existing['on_call'] = false;
        $existing['in_disposition'] = true;
        $existing['disposition_since'] = now()->utc()->toIso8601String();
        $existing['idle_since'] = now()->utc()->toIso8601String();
        $existing['last_seen_at'] = now()->utc()->toIso8601String();
        $map[$user->id] = $existing;
        $this->writeMap((int) $workspace->id, $map);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listOnline(Workspace $workspace, int $maxAgeSec = 150): array
    {
        $map = $this->readMap((int) $workspace->id);
        $online = [];
        $changed = false;
        $cutoff = now()->subSeconds($maxAgeSec);

        foreach ($map as $userId => $entry) {
            if (! is_array($entry)) {
                unset($map[$userId]);
                $changed = true;
                continue;
            }

            $seen = $entry['last_seen_at'] ?? null;
            try {
                $seenAt = filled($seen) ? \Carbon\Carbon::parse((string) $seen) : null;
            } catch (\Throwable) {
                $seenAt = null;
            }

            if (! $seenAt || $seenAt->lt($cutoff)) {
                unset($map[$userId]);
                $changed = true;
                continue;
            }

            $role = (string) ($entry['role'] ?? '');
            if (self::isExcludedFromMonitoring(
                $role,
                (string) ($entry['role_label'] ?? ''),
                null,
                null,
                (string) ($entry['name'] ?? ''),
            )) {
                unset($map[$userId]);
                $changed = true;
                continue;
            }
            if ($role !== '' && ! self::isMonitorableRole($role)) {
                unset($map[$userId]);
                $changed = true;
                continue;
            }

            $online[] = $entry;
        }

        if ($changed) {
            $this->writeMap((int) $workspace->id, $map);
        }

        usort($online, static fn (array $a, array $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $online;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    protected function readMap(int $workspaceId): array
    {
        $raw = Cache::get($this->mapKey($workspaceId), []);

        return is_array($raw) ? $raw : [];
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $map
     */
    protected function writeMap(int $workspaceId, array $map): void
    {
        Cache::put($this->mapKey($workspaceId), $map, self::TTL_SECONDS);
        $this->bumpPresenceVersion($workspaceId);
    }

    protected function bumpPresenceVersion(int $workspaceId): void
    {
        $key = $this->versionKey($workspaceId);
        Cache::put($key, ((int) Cache::get($key, 0)) + 1, self::TTL_SECONDS * 10);

        // Wake Call Monitoring SSE / WebSocket immediately when someone logs in or idles.
        try {
            app(MorpheusCallEventService::class)->bumpMonitoringVersion($workspaceId);
        } catch (\Throwable) {
            // Presence still works without the call-event bus.
        }
    }

    protected function mapKey(int $workspaceId): string
    {
        return 'communications.agent_presence_map.'.$workspaceId;
    }

    protected function versionKey(int $workspaceId): string
    {
        return 'communications.agent_presence_version.'.$workspaceId;
    }
}
