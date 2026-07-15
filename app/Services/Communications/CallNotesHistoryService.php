<?php

namespace App\Services\Communications;

use App\Models\CommunicationCallLog;
use App\Models\User;
use App\Models\Workspace;
use App\Support\SalesOps;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CallNotesHistoryService
{
    /**
     * Dialer members for the Call Notes agent picker.
     *
     * Admins / supervisors see all agents + team leads (never Admin / Super Admin).
     * Team leads see peer team leads (same family) and agents on their team.
     *
     * @return Collection<int, array{id: int, name: string, role: string}>
     */
    public function dialerAgents(Workspace $workspace, ?User $viewer = null, string $viewerTier = 'admin'): Collection
    {
        $dialerRoles = [
            'appointment_setter',
            'appointment_setter_team_lead',
            'closer',
            'closers_team_lead',
            'account_executive',
        ];

        $users = $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', $dialerRoles)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $this->isSelectableDialerAgent($user, (int) $workspace->id))
            ->values();

        if ($viewerTier === 'team_lead' && $viewer) {
            $users = $this->scopeForTeamLeadViewer($users, $viewer, (int) $workspace->id);
        }

        return $users
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->map(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'role' => SalesOps::roleLabel($user->pivot->role ?? null),
            ]);
    }

    /**
     * Notes + disposition history for one agent (or empty until an agent is chosen for admins).
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function notesForAgent(Workspace $workspace, int $userId, int $perPage = 25): LengthAwarePaginator
    {
        $paginator = $this->notesQuery($workspace, $userId)
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (CommunicationCallLog $row) => $this->mapNoteRow($row))
        );

        return $paginator;
    }

    /**
     * Full note set for CSV download (no pagination).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function allNotesForAgent(Workspace $workspace, int $userId): Collection
    {
        return $this->notesQuery($workspace, $userId)
            ->get()
            ->map(fn (CommunicationCallLog $row) => $this->mapNoteRow($row))
            ->values();
    }

    /**
     * @return Builder<\App\Models\CommunicationCallLog>
     */
    protected function notesQuery(Workspace $workspace, int $userId): Builder
    {
        return CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->where(function ($query) {
                $query->where(function ($note) {
                    $note->whereNotNull('note')->where('note', '!=', '');
                })->orWhere(function ($disposition) {
                    $disposition->whereNotNull('disposition')->where('disposition', '!=', '');
                })->orWhere(function ($meta) {
                    $meta->whereNotNull('meta')
                        ->where('meta', 'like', '%in_call_notes%');
                });
            })
            ->orderByDesc(DB::raw('COALESCE(ended_at, started_at, created_at)'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapNoteRow(CommunicationCallLog $row): array
    {
        $inCallNotes = trim((string) data_get($row->meta, 'in_call_notes', ''));
        $comment = trim((string) ($row->note ?? ''));
        $disposition = trim((string) ($row->disposition ?: data_get($row->meta, 'disposition', '')));
        $phone = $row->to_phone ?: $row->from_phone ?: '—';
        $when = $row->ended_at ?? $row->started_at ?? $row->created_at;
        $notes = $inCallNotes !== '' ? $inCallNotes : $comment;
        if ($inCallNotes !== '' && $comment !== '' && strcasecmp($inCallNotes, $comment) !== 0) {
            $notes = $inCallNotes."\n".$comment;
        }

        return [
            'id' => (int) $row->id,
            'phone' => $phone,
            'disposition' => $disposition !== '' ? $disposition : '—',
            'notes' => $notes !== '' ? $notes : '—',
            'duration_sec' => (int) ($row->duration_sec ?? 0),
            'when' => $when?->diffForHumans() ?? '—',
            'when_exact' => $when?->timezone(config('app.timezone'))->format('Y-m-d H:i:s') ?? '',
            'when_display' => $when?->timezone(config('app.timezone'))->format('D M j · g:i A') ?? '—',
        ];
    }

    protected function isSelectableDialerAgent(User $user, int $workspaceId): bool
    {
        if ($user->isSuperAdmin($workspaceId) || $user->isAdmin($workspaceId) || $user->isManager($workspaceId)) {
            return false;
        }

        $role = (string) ($user->pivot->role ?? $user->getWorkspaceRole($workspaceId) ?? '');
        if (SalesOps::isAdminPortalRole($role)) {
            return false;
        }

        $normalized = SalesOps::normalizeLegacyRole($role) ?: $role;

        return SalesOps::isAgentRole($normalized) || SalesOps::isTeamLeadRole($normalized);
    }

    /**
     * Team lead picker: peer team leads + agents assigned under them.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    protected function scopeForTeamLeadViewer(Collection $users, User $viewer, int $workspaceId): Collection
    {
        $viewerRole = (string) ($viewer->getWorkspaceRole($workspaceId) ?? '');
        $familyLeadRole = SalesOps::teamLeadRoleFor($viewerRole);
        $viewerId = (int) $viewer->id;

        return $users
            ->filter(function (User $user) use ($viewerId, $familyLeadRole) {
                $role = SalesOps::normalizeLegacyRole((string) ($user->pivot->role ?? '')) ?: (string) ($user->pivot->role ?? '');
                $leadId = (int) ($user->pivot->team_lead_user_id ?? 0);

                if ((int) $user->id === $viewerId) {
                    return true;
                }

                // Peer team leads in the same setter/closer family.
                if ($familyLeadRole && $role === $familyLeadRole) {
                    return true;
                }

                // Agents on this team lead's roster.
                return $leadId === $viewerId && SalesOps::isAgentRole($role);
            })
            ->values();
    }
}
