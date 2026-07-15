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
     * Map of team-lead id => list of active team members (setters).
     *
     * @return array<int, list<array{id:int,name:string}>>
     */
    public static function setterTeamMemberMap(Workspace $workspace): array
    {
        $map = [];
        foreach (self::setterTeamLeadsFor($workspace) as $lead) {
            $map[(int) $lead->id] = [];
        }

        foreach (self::activeSettersFor($workspace) as $setter) {
            $leadId = (int) ($setter->pivot->team_lead_user_id ?? 0);
            if ($leadId <= 0 || ! array_key_exists($leadId, $map)) {
                continue;
            }
            $map[$leadId][] = [
                'id' => (int) $setter->id,
                'name' => (string) $setter->name,
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

    public static function isTeamLead(User $user, Workspace $workspace): bool
    {
        $role = $user->getWorkspaceRole($workspace->id);

        return in_array($role, self::teamLeadRoles(), true);
    }
}
