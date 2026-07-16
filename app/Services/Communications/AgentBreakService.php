<?php

namespace App\Services\Communications;

use App\Models\AgentActivitySession;
use App\Models\User;
use App\Models\Workspace;
use App\Support\SalesOps;
use Illuminate\Support\Facades\DB;

class AgentBreakService
{
    public const BREAK_SECONDS = 300; // 5 minutes

    public const LUNCH_SECONDS = 1800; // 30 minutes

    public function __construct(
        protected AgentPresenceService $presence,
        protected MorpheusCallEventService $callEvents,
    ) {}

    public function plannedSecondsFor(string $type): int
    {
        return $type === AgentActivitySession::TYPE_LUNCH
            ? self::LUNCH_SECONDS
            : self::BREAK_SECONDS;
    }

    /**
     * Expire overdue active sessions (DB source of truth).
     */
    public function expireOverdue(?Workspace $workspace = null): int
    {
        $query = AgentActivitySession::query()
            ->where('status', AgentActivitySession::STATUS_ACTIVE)
            ->where('ends_at', '<=', now());

        if ($workspace) {
            $query->where('workspace_id', $workspace->id);
        }

        $expired = 0;
        $query->orderBy('id')->chunkById(100, function ($rows) use (&$expired) {
            foreach ($rows as $session) {
                $session->status = AgentActivitySession::STATUS_EXPIRED;
                $session->ended_at = now();
                $session->ended_reason = 'auto';
                $session->save();
                $expired++;

                $this->syncPresenceFromSession($session, clear: true);
            }
        });

        if ($expired > 0) {
            $this->callEvents->bumpMonitoringVersion();
        }

        return $expired;
    }

    public function activeForUser(Workspace $workspace, User $user): ?AgentActivitySession
    {
        $this->expireOverdue($workspace);

        return AgentActivitySession::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->where('status', AgentActivitySession::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{ok: bool, session?: array<string, mixed>, message?: string}
     */
    public function start(Workspace $workspace, User $user, string $type, array $meta = []): array
    {
        $type = strtolower(trim($type));
        if (! in_array($type, [AgentActivitySession::TYPE_BREAK, AgentActivitySession::TYPE_LUNCH], true)) {
            return ['ok' => false, 'message' => 'Invalid activity type.'];
        }

        $this->expireOverdue($workspace);

        return DB::transaction(function () use ($workspace, $user, $type, $meta) {
            // End any existing active session for this user first.
            $existing = AgentActivitySession::query()
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $user->id)
                ->where('status', AgentActivitySession::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->status = AgentActivitySession::STATUS_ENDED;
                $existing->ended_at = now();
                $existing->ended_reason = 'replaced';
                $existing->save();
            }

            $seconds = $this->plannedSecondsFor($type);
            $started = now();
            $session = AgentActivitySession::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'type' => $type,
                'status' => AgentActivitySession::STATUS_ACTIVE,
                'started_at' => $started,
                'ends_at' => $started->copy()->addSeconds($seconds),
                'planned_seconds' => $seconds,
            ]);

            $this->syncPresenceFromSession($session, clear: false, meta: $meta);
            $this->callEvents->bumpMonitoringVersion();

            return ['ok' => true, 'session' => $this->toArray($session)];
        });
    }

    /**
     * @return array{ok: bool, session?: array<string, mixed>|null, message?: string}
     */
    public function end(Workspace $workspace, User $user, string $reason = 'manual'): array
    {
        $this->expireOverdue($workspace);

        $session = AgentActivitySession::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->where('status', AgentActivitySession::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();

        if (! $session) {
            $this->clearPresenceBreak($workspace, $user);

            return ['ok' => true, 'session' => null, 'message' => 'No active break/lunch.'];
        }

        $session->status = $reason === 'auto'
            ? AgentActivitySession::STATUS_EXPIRED
            : AgentActivitySession::STATUS_ENDED;
        $session->ended_at = now();
        $session->ended_reason = $reason;
        $session->save();

        $this->syncPresenceFromSession($session, clear: true);
        $this->callEvents->bumpMonitoringVersion();

        return ['ok' => true, 'session' => $this->toArray($session)];
    }

    /**
     * Active break/lunch rows for Call Monitoring (workspace-wide).
     *
     * @return array<int, AgentActivitySession>
     */
    public function activeSessions(Workspace $workspace): array
    {
        $this->expireOverdue($workspace);

        return AgentActivitySession::query()
            ->with('user:id,name')
            ->where('workspace_id', $workspace->id)
            ->where('status', AgentActivitySession::STATUS_ACTIVE)
            ->where('ends_at', '>', now())
            ->orderBy('started_at')
            ->get()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(AgentActivitySession $session): array
    {
        $endsTs = $session->ends_at ? $session->ends_at->getTimestamp() : time();
        $remaining = max(0, $endsTs - time());

        return [
            'id' => (int) $session->id,
            'workspace_id' => (int) $session->workspace_id,
            'user_id' => (int) $session->user_id,
            'type' => (string) $session->type,
            'status' => (string) $session->status,
            'started_at' => optional($session->started_at)->utc()->toIso8601String(),
            'ends_at' => optional($session->ends_at)->utc()->toIso8601String(),
            'ended_at' => optional($session->ended_at)?->utc()?->toIso8601String(),
            'planned_seconds' => (int) $session->planned_seconds,
            'remaining_seconds' => $remaining,
            'ended_reason' => $session->ended_reason,
            'label' => $session->type === AgentActivitySession::TYPE_LUNCH ? 'Lunch' : 'Break',
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function syncPresenceFromSession(AgentActivitySession $session, bool $clear, array $meta = []): void
    {
        $workspace = Workspace::query()->find($session->workspace_id);
        $user = User::query()->find($session->user_id);
        if (! $workspace || ! $user) {
            return;
        }

        if ($clear) {
            $this->clearPresenceBreak($workspace, $user);

            return;
        }

        $membership = $workspace->users()->where('users.id', $user->id)->first();
        $role = (string) ($membership?->pivot?->role ?? '');
        $extension = preg_replace('/\D/', '', (string) ($meta['extension'] ?? $membership?->pivot?->morpheus_extension_num ?? '')) ?: null;

        $this->presence->heartbeat($user, $workspace, [
            'break_status' => $session->type === AgentActivitySession::TYPE_LUNCH ? 'lunch' : 'break',
            'break_ends_at' => optional($session->ends_at)->utc()->toIso8601String(),
            'on_call' => false,
            'in_disposition' => false,
            'extension' => $extension,
            'role' => $role,
            'role_label' => SalesOps::roleLabel($role),
            'name' => $user->name,
            'dial_mode' => $meta['dial_mode'] ?? null,
            'auto_session_active' => (bool) ($meta['auto_session_active'] ?? false),
            'auto_paused' => true,
        ]);
    }

    protected function clearPresenceBreak(Workspace $workspace, User $user): void
    {
        $membership = $workspace->users()->where('users.id', $user->id)->first();
        $role = (string) ($membership?->pivot?->role ?? '');
        $extension = preg_replace('/\D/', '', (string) ($membership?->pivot?->morpheus_extension_num ?? '')) ?: null;

        $this->presence->heartbeat($user, $workspace, [
            'break_status' => 'none',
            'break_ends_at' => null,
            'on_call' => false,
            'in_disposition' => false,
            'extension' => $extension,
            'role' => $role,
            'role_label' => SalesOps::roleLabel($role),
            'name' => $user->name,
            'auto_paused' => false,
        ]);
    }
}
