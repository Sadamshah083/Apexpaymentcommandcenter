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
        'appointment_setter_team_lead',
        'closers',
        'closer',
        'closers_team_lead',
    ];

    /** Never show these accounts on Call Monitoring. */
    public const EXCLUDED_ROLES = [
        'super_admin',
        'admin',
    ];

    public static function isMonitorableRole(?string $role): bool
    {
        $normalized = \App\Support\SalesOps::normalizeLegacyRole($role) ?: (string) $role;
        if ($normalized === '' || self::isExcludedRole($normalized)) {
            return false;
        }

        return in_array($normalized, self::MONITOR_ROLES, true)
            || in_array((string) $role, self::MONITOR_ROLES, true);
    }

    public static function isExcludedRole(?string $role): bool
    {
        $normalized = \App\Support\SalesOps::normalizeLegacyRole((string) $role) ?: (string) $role;

        return in_array($normalized, self::EXCLUDED_ROLES, true)
            || in_array((string) $role, self::EXCLUDED_ROLES, true);
    }

    /**
     * True when this account must never appear on Call Monitoring (live, idle, disposition).
     */
    public static function isExcludedFromMonitoring(
        ?string $role = null,
        ?string $roleLabel = null,
        ?User $user = null,
        ?int $workspaceId = null
    ): bool {
        if ($user && ($user->isSuperAdmin($workspaceId) || $user->isAdmin($workspaceId))) {
            return true;
        }

        if (self::isExcludedRole($role)) {
            return true;
        }

        $label = strtolower(trim((string) $roleLabel));
        if (in_array($label, ['super admin', 'admin'], true)) {
            return true;
        }

        // Non-agent portal roles stay off the board when a role is known.
        if (filled($role) && ! self::isMonitorableRole($role)) {
            return true;
        }

        return false;
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

        $nowIso = now()->utc()->toIso8601String();
        $idleSince = $existing['idle_since'] ?? $nowIso;
        if ($onCall) {
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
            'name' => (string) ($payload['name'] ?? $existing['name'] ?? $user->name),
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

        // Wake Call Monitoring SSE / light polls immediately when someone logs in or idles.
        try {
            app(MorpheusCallEventService::class)->bumpMonitoringVersion();
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
