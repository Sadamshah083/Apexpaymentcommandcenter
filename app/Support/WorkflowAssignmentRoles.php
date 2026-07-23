<?php

namespace App\Support;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;

class WorkflowAssignmentRoles
{
    /**
     * Portal team lead roles eligible to receive admin-assigned lead batches.
     *
     * @return list<string>
     */
    public static function teamLeadRoles(): array
    {
        return [
            'appointment_setter_team_lead',
            'closers_team_lead',
        ];
    }

    /**
     * Team lead role that may receive enriched import leads for setter distribution.
     */
    public static function setterTeamLeadRole(): string
    {
        return 'appointment_setter_team_lead';
    }

    /**
     * Team lead role for B2B closer / closers campaign assignment.
     */
    public static function closerTeamLeadRole(): string
    {
        return 'closers_team_lead';
    }

    /**
     * @return Collection<int, User>
     */
    public static function setterTeamLeadsFor(Workspace $workspace): Collection
    {
        return $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivot('role', self::setterTeamLeadRole())
            ->orderBy('users.name')
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    public static function closerTeamLeadsFor(Workspace $workspace): Collection
    {
        return $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivot('role', self::closerTeamLeadRole())
            ->orderBy('users.name')
            ->get();
    }

    /**
     * Active appointment setters in the workspace (team members under team leads).
     *
     * @return Collection<int, User>
     */
    public static function activeSettersFor(Workspace $workspace): Collection
    {
        return $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivot('role', 'appointment_setter')
            ->orderBy('users.name')
            ->get();
    }

    /**
     * Active closers in the workspace.
     *
     * @return Collection<int, User>
     */
    public static function activeClosersFor(Workspace $workspace): Collection
    {
        return $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivot('role', 'closer')
            ->orderBy('users.name')
            ->get();
    }

    /**
     * Active setters + closers (agents that can receive import assignments).
     *
     * @return Collection<int, User>
     */
    public static function activeAssignableAgentsFor(Workspace $workspace): Collection
    {
        return $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', ['appointment_setter', 'closer'])
            ->orderBy('users.name')
            ->get();
    }

    /**
     * Setters that report to a given Appointment Setter Team Lead.
     *
     * @return Collection<int, User>
     */
    public static function settersForTeamLead(Workspace $workspace, int $teamLeadId): Collection
    {
        if ($teamLeadId <= 0) {
            return new Collection;
        }

        return $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivot('role', 'appointment_setter')
            ->wherePivot('team_lead_user_id', $teamLeadId)
            ->orderBy('users.name')
            ->get();
    }

    /**
     * Closers that report to a given Closers Team Lead.
     *
     * @return Collection<int, User>
     */
    public static function closersForTeamLead(Workspace $workspace, int $teamLeadId): Collection
    {
        if ($teamLeadId <= 0) {
            return new Collection;
        }

        return $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivot('role', 'closer')
            ->wherePivot('team_lead_user_id', $teamLeadId)
            ->orderBy('users.name')
            ->get();
    }

    /**
     * Members under a team lead based on that lead's workspace role.
     *
     * @return Collection<int, User>
     */
    public static function agentsForTeamLead(Workspace $workspace, User $teamLead): Collection
    {
        $role = (string) ($teamLead->pivot->role ?? $teamLead->getWorkspaceRole($workspace->id) ?? '');

        if ($role === self::closerTeamLeadRole()) {
            $team = self::closersForTeamLead($workspace, (int) $teamLead->id);

            return $team->isNotEmpty() ? $team : self::activeClosersFor($workspace);
        }

        $team = self::settersForTeamLead($workspace, (int) $teamLead->id);

        return $team->isNotEmpty() ? $team : self::activeSettersFor($workspace);
    }

    /**
     * Map of team-lead id => list of active team members (setters).
     *
     * @return array<int, list<array{id:int,name:string}>>
     */
    public static function setterTeamMemberMap(Workspace $workspace): array
    {
        return self::buildTeamMemberMap(
            self::setterTeamLeadsFor($workspace),
            self::activeSettersFor($workspace),
        );
    }

    /**
     * Map of team-lead id => list of active team members (setters + closers).
     *
     * @return array<int, list<array{id:int,name:string}>>
     */
    public static function assignableTeamMemberMap(Workspace $workspace): array
    {
        return self::buildTeamMemberMap(
            self::assignableTeamLeadsFor($workspace),
            self::activeAssignableAgentsFor($workspace),
        );
    }

    /**
     * Team leads eligible for enriched import assignment, campaign-linked first.
     *
     * @return Collection<int, User>
     */
    public static function assignableTeamLeadsFor(Workspace $workspace): Collection
    {
        $campaignNames = $workspace->campaigns()->pluck('name', 'id');

        return self::teamLeadsFor($workspace)
            ->sortBy([
                fn (User $lead) => (int) ($lead->pivot->campaign_id ?? 0) > 0 ? 0 : 1,
                fn (User $lead) => strtolower((string) ($campaignNames[(int) ($lead->pivot->campaign_id ?? 0)] ?? 'zzz')),
                fn (User $lead) => strtolower((string) $lead->name),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, User>  $teamLeads
     * @param  Collection<int, User>  $members
     * @return array<int, list<array{id:int,name:string}>>
     */
    protected static function buildTeamMemberMap(Collection $teamLeads, Collection $members): array
    {
        $map = [];
        foreach ($teamLeads as $lead) {
            $map[(int) $lead->id] = [];
        }

        foreach ($members as $member) {
            $leadId = (int) ($member->pivot->team_lead_user_id ?? 0);
            if ($leadId <= 0 || ! array_key_exists($leadId, $map)) {
                continue;
            }
            $map[$leadId][] = [
                'id' => (int) $member->id,
                'name' => (string) $member->name,
            ];
        }

        return $map;
    }

    /**
     * @return Collection<int, User>
     */
    public static function teamLeadsFor(Workspace $workspace): Collection
    {
        return $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', self::teamLeadRoles())
            ->orderBy('users.name')
            ->get();
    }

    public static function isAssignableTeamLeadRole(?string $role): bool
    {
        return in_array($role, self::teamLeadRoles(), true);
    }

    public static function isTeamLead(User $user, Workspace $workspace): bool
    {
        $role = $user->getWorkspaceRole($workspace->id);

        return in_array($role, self::teamLeadRoles(), true);
    }
}
