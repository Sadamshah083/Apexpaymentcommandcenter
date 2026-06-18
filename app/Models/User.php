<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'email', 'password', 'current_workspace_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
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
            ->withPivot('role', 'status', 'invited_at', 'joined_at')
            ->withTimestamps();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Workspace>
     */
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

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(WorkflowLead::class, 'assigned_user_id');
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function getWorkspaceRole($workspaceId = null)
    {
        $workspaceId = $workspaceId ?: $this->current_workspace_id;
        if (! $workspaceId) {
            return null;
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

    public function isWorkspaceAdmin(?int $workspaceId = null): bool
    {
        $workspaceId = $workspaceId ?: $this->current_workspace_id;
        if (! $workspaceId) {
            return false;
        }

        if ($this->relationLoaded('currentWorkspace') && $this->currentWorkspace?->id === $workspaceId) {
            if ($this->currentWorkspace->admin_id === $this->id) {
                return true;
            }
        }

        if ($this->relationLoaded('workspaces')) {
            $workspace = $this->workspaces->firstWhere('id', $workspaceId);
            if ($workspace?->admin_id === $this->id) {
                return true;
            }
        } else {
            $workspace = Workspace::find($workspaceId);
            if ($workspace?->admin_id === $this->id) {
                return true;
            }
        }

        return $this->getWorkspaceRole($workspaceId) === 'admin';
    }

    public function hasActiveMembership(?int $workspaceId = null): bool
    {
        return $this->getWorkspaceRole($workspaceId) !== null;
    }

    public function isSuspendedInWorkspace(?int $workspaceId = null): bool
    {
        $workspaceId = $workspaceId ?: $this->current_workspace_id;
        if (! $workspaceId) {
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

    public function canAccessAdminPortal(?int $workspaceId = null): bool
    {
        return $this->isWorkspaceAdmin($workspaceId);
    }

    public function canAccessMarketerPortal(?int $workspaceId = null): bool
    {
        return $this->hasActiveMembership($workspaceId);
    }

    public function isMarketerOnly(?int $workspaceId = null): bool
    {
        return $this->getWorkspaceRole($workspaceId) === 'marketer';
    }
}

