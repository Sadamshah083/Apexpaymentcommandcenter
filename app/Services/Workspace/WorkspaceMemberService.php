<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceSyncEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Support\SalesOps;

class WorkspaceMemberService
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
        protected WorkspaceSyncService $syncService,
    ) {}

    /**
     * Create an agent account with username/password (no email invitation).
     */
    public function createAgent(
        Workspace $workspace,
        User $createdBy,
        string $username,
        string $password,
        string $role = 'sdr',
    ): User {
        $this->workspaceContext->ensureCanManageMembers($createdBy, $workspace);

        $username = trim($username);
        $role = $this->normalizeRole($role);

        if ($username === '') {
            throw ValidationException::withMessages([
                'username' => 'Username is required.',
            ]);
        }

        if (User::where('name', $username)->exists()) {
            throw ValidationException::withMessages([
                'username' => 'This username is already taken.',
            ]);
        }

        if ($workspace->users()->where('users.name', $username)->exists()) {
            throw ValidationException::withMessages([
                'username' => 'This user is already a member of the workspace.',
            ]);
        }

        $user = User::create([
            'name' => $username,
            'email' => $this->syntheticEmail($username, $workspace->name),
            'password' => Hash::make($password),
            'current_workspace_id' => $workspace->id,
        ]);

        $workspace->users()->attach($user->id, [
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->syncService->record(
            $workspace,
            'member.joined',
            'user',
            $user->id,
            ['username' => $username, 'role' => $role],
            $createdBy->id
        );

        return $user;
    }

    protected function syntheticEmail(string $username, string $workspaceName): string
    {
        $email = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $username))
            .'@'
            .strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $workspaceName))
            .'.local';

        if (User::where('email', $email)->exists()) {
            $email = Str::random(5).'_'.$email;
        }

        return $email;
    }

    /**
     * @deprecated Use createAgent() instead.
     *
     * @return array{member: User, invitation: WorkspaceInvitation, invite_url: string}
     */
    public function inviteMember(
        Workspace $workspace,
        User $invitedBy,
        string $email,
        string $role,
        ?string $username = null,
    ): array {
        $this->workspaceContext->ensureCanManageMembers($invitedBy, $workspace);

        $email = strtolower(trim($email));
        $role = $this->normalizeRole($role);

        $existingMember = $workspace->users()->where('users.email', $email)->first();
        if ($existingMember) {
            throw ValidationException::withMessages([
                'email' => 'This user is already a member of the workspace.',
            ]);
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            if (! $username) {
                throw ValidationException::withMessages([
                    'username' => 'Username is required when inviting a new user.',
                ]);
            }

            if (User::where('name', $username)->exists()) {
                throw ValidationException::withMessages([
                    'username' => 'This username is already taken.',
                ]);
            }

            $user = User::create([
                'name' => $username,
                'email' => $email,
                'password' => Hash::make(Str::random(32)),
            ]);
        }

        $invitation = WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'invited_by' => $invitedBy->id,
            'user_id' => $user->id,
            'email' => $email,
            'username' => $user->name,
            'role' => $role,
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        $workspace->users()->attach($user->id, [
            'role' => $role,
            'status' => 'invited',
            'invited_at' => now(),
        ]);

        $this->syncService->record(
            $workspace,
            'member.invited',
            'user',
            $user->id,
            ['email' => $email, 'role' => $role],
            $invitedBy->id
        );

        return [
            'member' => $user,
            'invitation' => $invitation,
            'invite_url' => route('portal.invite.accept', $invitation->token),
        ];
    }

    public function acceptInvitation(string $token, string $password, ?string $username = null): User
    {
        $invitation = WorkspaceInvitation::where('token', $token)->firstOrFail();

        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages([
                'token' => 'This invitation has already been accepted.',
            ]);
        }

        if ($invitation->isExpired()) {
            throw ValidationException::withMessages([
                'token' => 'This invitation has expired. Ask your workspace admin for a new invite.',
            ]);
        }

        $user = $invitation->user ?? User::where('email', $invitation->email)->firstOrFail();

        return DB::transaction(function () use ($invitation, $user, $password, $username) {
            if ($username && $username !== $user->name) {
                if (User::where('name', $username)->where('id', '!=', $user->id)->exists()) {
                    throw ValidationException::withMessages([
                        'username' => 'This username is already taken.',
                    ]);
                }
                $user->update(['name' => $username]);
            }

            $user->update(['password' => Hash::make($password)]);

            $invitation->workspace->users()->updateExistingPivot($user->id, [
                'status' => 'active',
                'joined_at' => now(),
            ]);

            if (! $user->current_workspace_id) {
                $user->update(['current_workspace_id' => $invitation->workspace_id]);
            }

            $invitation->update(['accepted_at' => now()]);

            $this->syncService->record(
                $invitation->workspace,
                'member.joined',
                'user',
                $user->id,
                ['email' => $user->email, 'role' => $invitation->role],
                $user->id
            );

            return $user->fresh();
        });
    }

    public function updateMemberRole(Workspace $workspace, User $admin, User $member, string $role): void
    {
        $this->workspaceContext->ensureCanManageMembers($admin, $workspace);

        if ($workspace->admin_id === $member->id && $role !== 'admin') {
            throw ValidationException::withMessages([
                'role' => 'The workspace owner must remain an administrator.',
            ]);
        }

        if (! in_array($role, array_keys(config('sales_ops.roles', [])), true)) {
            throw ValidationException::withMessages([
                'role' => 'Invalid role selected.',
            ]);
        }

        if (! $workspace->users()->where('user_id', $member->id)->exists()) {
            abort(404);
        }

        $workspace->users()->updateExistingPivot($member->id, ['role' => $role]);

        $this->syncService->record(
            $workspace,
            'member.role_updated',
            'user',
            $member->id,
            array_merge($this->memberPayload($member, $workspace), ['role' => $role]),
            $admin->id
        );
    }

    public function suspendMember(Workspace $workspace, User $admin, User $member): void
    {
        $this->workspaceContext->ensureCanManageMembers($admin, $workspace);

        if ($workspace->admin_id === $member->id) {
            throw ValidationException::withMessages([
                'member' => 'The workspace owner cannot be suspended.',
            ]);
        }

        $pivot = $workspace->users()->where('user_id', $member->id)->first()?->pivot;
        if (($pivot->status ?? 'active') === 'suspended') {
            throw ValidationException::withMessages([
                'member' => "{$member->name} is already suspended.",
            ]);
        }

        $workspace->users()->updateExistingPivot($member->id, ['status' => 'suspended']);

        $this->syncService->record(
            $workspace,
            'member.suspended',
            'user',
            $member->id,
            $this->memberPayload($member, $workspace),
            $admin->id
        );
    }

    public function reactivateMember(Workspace $workspace, User $admin, User $member): void
    {
        $this->workspaceContext->ensureCanManageMembers($admin, $workspace);

        $pivot = $workspace->users()->where('user_id', $member->id)->first()?->pivot;
        if (($pivot->status ?? 'active') === 'active') {
            throw ValidationException::withMessages([
                'member' => "{$member->name} is already active.",
            ]);
        }

        $workspace->users()->updateExistingPivot($member->id, [
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->syncService->record(
            $workspace,
            'member.reactivated',
            'user',
            $member->id,
            $this->memberPayload($member, $workspace),
            $admin->id
        );
    }

    public function removeMember(Workspace $workspace, User $admin, User $member): void
    {
        $this->workspaceContext->ensureCanManageMembers($admin, $workspace);

        if ($workspace->admin_id === $member->id) {
            throw ValidationException::withMessages([
                'member' => 'The workspace owner cannot be removed.',
            ]);
        }

        $payload = $this->memberPayload($member, $workspace);

        $workspace->users()->detach($member->id);

        if ($member->current_workspace_id === $workspace->id) {
            $fallback = $member->workspaces()->first();
            $member->update(['current_workspace_id' => $fallback?->id]);
        }

        WorkspaceInvitation::where('workspace_id', $workspace->id)
            ->where('user_id', $member->id)
            ->whereNull('accepted_at')
            ->delete();

        $this->syncService->record(
            $workspace,
            'member.removed',
            'user',
            $member->id,
            $payload,
            $admin->id
        );
    }

    protected function normalizeRole(string $role): string
    {
        $allowed = array_keys(config('sales_ops.roles', []));

        return in_array($role, $allowed, true) ? $role : 'sdr';
    }

    /**
     * @return array{name: string, email: string, role: string|null, status: string|null}
     */
    protected function memberPayload(User $member, Workspace $workspace): array
    {
        $pivot = $workspace->users()->where('user_id', $member->id)->first()?->pivot;

        return [
            'name' => $member->name,
            'email' => $member->email,
            'role' => $pivot->role ?? null,
            'status' => $pivot->status ?? null,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, WorkspaceInvitation>
     */
    public function pendingInvitations(Workspace $workspace)
    {
        return WorkspaceInvitation::where('workspace_id', $workspace->id)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->get();
    }
}
