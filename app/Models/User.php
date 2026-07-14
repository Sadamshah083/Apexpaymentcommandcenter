<?php

namespace App\Models;

use App\Support\AdminModules;
use App\Support\MemberModuleAccess;
use App\Support\PortalModules;
use App\Support\SalesOps;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'current_workspace_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->withPivot(
                'role',
                'team_lead_user_id',
                'campaign_id',
                'status',
                'invited_at',
                'joined_at',
                'module_permissions',
                'morpheus_user_id',
                'morpheus_extension_id',
                'morpheus_extension_num',
            )
            ->withTimestamps();
    }

    public function switchableWorkspaces()
    {
        $membershipWorkspaces = $this->relationLoaded('workspaces')
            ? $this->workspaces
            : $this->workspaces()->get();

        $activeMemberships = $membershipWorkspaces->filter(
            fn (Workspace $workspace) => ($workspace->pivot->status ?? 'active') === 'active'
        );

        $ownedWorkspaces = Workspace::query()
            ->where('admin_id', $this->id)
            ->whereNotIn('id', $activeMemberships->pluck('id'))
            ->orderBy('name')
            ->get();

        return $activeMemberships
            ->concat($ownedWorkspaces)
            ->unique('id')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function adminSwitchableWorkspaces()
    {
        return $this->switchableWorkspaces()
            ->filter(fn (Workspace $workspace) => $this->canAccessAdminPortal($workspace->id))
            ->values();
    }

    public function portalSwitchableWorkspaces()
    {
        return $this->switchableWorkspaces()
            ->filter(fn (Workspace $workspace) => $this->canAccessPortal($workspace->id))
            ->values();
    }

    public function effectivePortalRole(?int $workspaceId = null): ?string
    {
        $role = $this->getWorkspaceRole($workspaceId);

        return $role ? SalesOps::normalizeLegacyRole($role) : null;
    }

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(WorkflowLead::class, 'assigned_user_id');
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function getWorkspaceRole(?int $workspaceId = null): ?string
    {
        $workspaceId = $workspaceId ?: $this->current_workspace_id;
        if (! $workspaceId) {
            return null;
        }

        if ($this->isSuperAdmin($workspaceId)) {
            return 'super_admin';
        }

        if ($this->relationLoaded('workspaces')) {
            $workspace = $this->workspaces->firstWhere('id', $workspaceId);
            if (! $workspace || ($workspace->pivot->status ?? 'active') !== 'active') {
                return null;
            }

            return $workspace->pivot->role;
        }

        $workspace = $this->workspaces()->where('workspace_id', $workspaceId)->first();
        if (! $workspace || ($workspace->pivot->status ?? 'active') !== 'active') {
            return null;
        }

        return $workspace->pivot->role;
    }

    public function isSuperAdmin(?int $workspaceId = null): bool
    {
        $workspaceId = $workspaceId ?: $this->current_workspace_id;
        if (! $workspaceId) {
            return false;
        }

        if ($this->relationLoaded('currentWorkspace') && $this->currentWorkspace?->id === $workspaceId) {
            return $this->currentWorkspace->admin_id === $this->id;
        }

        if ($this->relationLoaded('workspaces')) {
            $workspace = $this->workspaces->firstWhere('id', $workspaceId);
            if ($workspace) {
                return $workspace->admin_id === $this->id;
            }
        }

        $workspace = Workspace::find($workspaceId);

        return $workspace && $workspace->admin_id === $this->id;
    }

    public function isAdmin(?int $workspaceId = null): bool
    {
        return $this->getWorkspaceRole($workspaceId) === 'admin';
    }

    public function isManager(?int $workspaceId = null): bool
    {
        return $this->getWorkspaceRole($workspaceId) === 'manager';
    }

    public function isAppointmentSetter(?int $workspaceId = null): bool
    {
        return $this->effectivePortalRole($workspaceId) === 'appointment_setter';
    }

    public function isAppointmentSetterTeamLead(?int $workspaceId = null): bool
    {
        return $this->getWorkspaceRole($workspaceId) === 'appointment_setter_team_lead';
    }

    public function isClosersTeamLead(?int $workspaceId = null): bool
    {
        return $this->getWorkspaceRole($workspaceId) === 'closers_team_lead';
    }

    public function isCloser(?int $workspaceId = null): bool
    {
        return $this->effectivePortalRole($workspaceId) === 'closer';
    }

    public function isAdminOfAnyWorkspace(): bool
    {
        if (Workspace::query()->where('admin_id', $this->id)->exists()) {
            return true;
        }

        return $this->workspaces()
            ->wherePivotIn('role', ['super_admin', 'admin', 'manager'])
            ->wherePivot('status', 'active')
            ->get()
            ->contains(fn (Workspace $workspace) => $this->canAccessAdminPortal($workspace->id));
    }

    public function firstAdminWorkspace(): ?Workspace
    {
        $owned = Workspace::query()
            ->where('admin_id', $this->id)
            ->orderBy('name')
            ->first();

        if ($owned) {
            return $owned;
        }

        return $this->workspaces()
            ->wherePivotIn('role', ['super_admin', 'admin', 'manager'])
            ->wherePivot('status', 'active')
            ->orderBy('workspaces.name')
            ->first();
    }

    public function ensureAdminPortalWorkspace(): ?Workspace
    {
        if ($this->current_workspace_id && $this->canAccessAdminPortal($this->current_workspace_id)) {
            return Workspace::find($this->current_workspace_id);
        }

        $adminWorkspace = $this->firstAdminWorkspace();
        if (! $adminWorkspace) {
            return null;
        }

        if ($this->current_workspace_id !== $adminWorkspace->id) {
            $this->update(['current_workspace_id' => $adminWorkspace->id]);
        }

        return $adminWorkspace;
    }

    public function hasActiveMembership(?int $workspaceId = null): bool
    {
        return $this->getWorkspaceRole($workspaceId) !== null;
    }

    public function isSuspendedInWorkspace(?int $workspaceId = null): bool
    {
        $workspaceId = $workspaceId ?: $this->current_workspace_id;
        if (! $workspaceId || $this->isSuperAdmin($workspaceId)) {
            return false;
        }

        $workspace = $this->workspaces()->where('workspace_id', $workspaceId)->first();

        return $workspace && ($workspace->pivot->status ?? 'active') === 'suspended';
    }

    public function hasAnySuspendedMembership(): bool
    {
        if ($this->relationLoaded('workspaces')) {
            return $this->workspaces->contains(
                fn (Workspace $workspace) => ($workspace->pivot->status ?? 'active') === 'suspended'
            );
        }

        return $this->workspaces()->wherePivot('status', 'suspended')->exists();
    }

    public function firstActiveWorkspace(): ?Workspace
    {
        return $this->switchableWorkspaces()->first();
    }

    public function firstPortalWorkspace(): ?Workspace
    {
        return $this->portalSwitchableWorkspaces()->first();
    }

    public function canAccessAdminPortal(?int $workspaceId = null): bool
    {
        if ($workspaceId) {
            if ($this->isSuperAdmin($workspaceId)) {
                return true;
            }

            $role = $this->getWorkspaceRole($workspaceId);
            if ($role === 'super_admin') {
                return true;
            }

            if (! $this->isAdmin($workspaceId) && ! $this->isManager($workspaceId)) {
                return false;
            }

            $permissions = $this->getModulePermissions($workspaceId);

            return $permissions === null || count($permissions) > 0;
        }

        return $this->isAdminOfAnyWorkspace();
    }

    /**
     * @return list<string>|null null = unrestricted access to all modules
     */
    public function getModulePermissions(?int $workspaceId = null): ?array
    {
        $workspaceId = $workspaceId ?: $this->current_workspace_id;
        if (! $workspaceId || $this->isSuperAdmin($workspaceId)) {
            return null;
        }

        $pivot = $this->workspaceMembershipPivot($workspaceId);
        if (! $pivot) {
            return null;
        }

        $raw = $pivot->module_permissions ?? null;
        if ($raw === null) {
            return null;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? array_values($decoded) : [];
        }

        return is_array($raw) ? array_values($raw) : [];
    }

    public function usesRestrictedModuleAccess(?int $workspaceId = null): bool
    {
        return is_array($this->getModulePermissions($workspaceId));
    }

    public function canAccessAdminModule(string $module, ?int $workspaceId = null): bool
    {
        if (! AdminModules::isValid($module)) {
            return false;
        }

        $workspaceId = $workspaceId ?: $this->current_workspace_id;
        if (! $workspaceId || ! $this->canAccessAdminPortal($workspaceId)) {
            return false;
        }

        if (AdminModules::all()[$module]['always_available'] ?? false) {
            return true;
        }

        if ($this->isSuperAdmin($workspaceId)) {
            return true;
        }

        $permissions = $this->getModulePermissions($workspaceId);
        if ($permissions === null) {
            return true;
        }

        return in_array($module, $permissions, true);
    }

    public function canAssignModulePermissions(?int $workspaceId = null): bool
    {
        $workspaceId = $workspaceId ?: $this->current_workspace_id;
        if (! $workspaceId) {
            return false;
        }

        return $this->isSuperAdmin($workspaceId)
            || $this->isAdmin($workspaceId);
    }

    public function canManageWorkspaceMembers(?int $workspaceId = null): bool
    {
        return $this->isSuperAdmin($workspaceId ?: $this->current_workspace_id);
    }

    protected function workspaceMembershipPivot(int $workspaceId): ?object
    {
        if ($this->relationLoaded('workspaces')) {
            return $this->workspaces->firstWhere('id', $workspaceId)?->pivot;
        }

        return $this->workspaces()->where('workspace_id', $workspaceId)->first()?->pivot;
    }

    public function canAccessPortal(?int $workspaceId = null): bool
    {
        $role = $this->getWorkspaceRole($workspaceId);

        if (! $role || ! SalesOps::isPortalRole($role)) {
            return false;
        }

        if (MemberModuleAccess::usesPortalModules($role)) {
            $permissions = $this->getModulePermissions($workspaceId);

            return ! is_array($permissions) || count($permissions) > 0;
        }

        return true;
    }

    public function canAccessPortalModule(string $module, ?int $workspaceId = null): bool
    {
        if (! PortalModules::isValid($module)) {
            return false;
        }

        $workspaceId = $workspaceId ?: $this->current_workspace_id;
        if (! $workspaceId || ! $this->canAccessPortal($workspaceId)) {
            return false;
        }

        $role = $this->getWorkspaceRole($workspaceId);
        if (! PortalModules::isAvailableForRole($module, (string) $role)) {
            return false;
        }

        if (PortalModules::all()[$module]['always_available'] ?? false) {
            return true;
        }

        $permissions = $this->getModulePermissions($workspaceId);
        if ($permissions === null) {
            return true;
        }

        return in_array($module, $permissions, true);
    }

    public function portalDashboardRoute(): string
    {
        return match ($this->effectivePortalRole()) {
            'appointment_setter' => 'portal.setter.dashboard',
            'appointment_setter_team_lead' => 'portal.setter-team.dashboard',
            'closers_team_lead' => 'portal.closer-team.dashboard',
            'closer' => 'portal.closer.dashboard',
            default => 'portal.login',
        };
    }

    /** @deprecated Use canAccessAdminPortal() or isSuperAdmin() */
    public function isWorkspaceAdmin(?int $workspaceId = null): bool
    {
        return $this->canAccessAdminPortal($workspaceId);
    }
}
