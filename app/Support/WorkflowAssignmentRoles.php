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
