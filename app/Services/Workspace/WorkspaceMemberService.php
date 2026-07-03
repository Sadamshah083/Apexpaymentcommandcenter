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
use App\Support\AdminModules;
use App\Support\MemberModuleAccess;
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
        string $role = 'appointment_setter',
        ?array $modulePermissions = null,
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
            'module_permissions' => $this->encodeModulePermissions($role, $modulePermissions),
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

    public function updateMemberProfile(
        Workspace $workspace,
        User $admin,
        User $member,
        string $username,
        string $email,
        ?string $password = null,
    ): User {
        $this->workspaceContext->ensureCanManageMembers($admin, $workspace);
        $this->ensureMemberIsEditable($workspace, $member, 'account');

        if (! $workspace->users()->where('user_id', $member->id)->exists()) {
            abort(404);
        }

        $username = trim($username);
        $email = strtolower(trim($email));

        if ($username === '') {
            throw ValidationException::withMessages([
                'username' => 'Username is required.',
            ]);
        }

        if (User::where('name', $username)->where('id', '!=', $member->id)->exists()) {
            throw ValidationException::withMessages([
                'username' => 'This username is already taken.',
            ]);
        }

        if (User::where('email', $email)->where('id', '!=', $member->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This email is already in use.',
            ]);
        }

        $updates = [
            'name' => $username,
            'email' => $email,
        ];

        if ($password !== null && $password !== '') {
            $updates['password'] = Hash::make($password);
        }

        $member->update($updates);

        $this->syncService->record(
            $workspace,
            'member.updated',
            'user',
            $member->id,
            $this->memberPayload($member->fresh(), $workspace),
            $admin->id
        );

        return $member->fresh();
    }

    public function updateMemberRole(Workspace $workspace, User $admin, User $member, string $role): void
    {
        $this->workspaceContext->ensureCanManageMembers($admin, $workspace);
        $this->ensureMemberIsEditable($workspace, $member, 'role');

        if ($role === 'super_admin') {
            throw ValidationException::withMessages([
                'role' => 'Super Admin is assigned to the workspace owner only.',
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

        $workspace->users()->updateExistingPivot($member->id, [
            'role' => $role,
            'module_permissions' => $this->modulePermissionsForRoleChange($role, $member, $workspace),
        ]);

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
        $this->ensureMemberIsEditable($workspace, $member, 'member');

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
        $this->ensureMemberIsEditable($workspace, $member, 'member');

        $payload = $this->memberPayload($member, $workspace);

        $workspace->users()->detach($member->id);

        if ($member->current_workspace_id === $workspace->id) {
            $fallback = $member->switchableWorkspaces()->first();
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

    public function resetMemberPassword(Workspace $workspace, User $admin, User $member, string $password): void
    {
        $this->workspaceContext->ensureCanManageMembers($admin, $workspace);
        $this->ensureMemberIsEditable($workspace, $member, 'password');

        if (! $workspace->users()->where('user_id', $member->id)->exists()) {
            abort(404);
        }

        $member->update(['password' => Hash::make($password)]);

        $this->syncService->record(
            $workspace,
            'member.password_reset',
            'user',
            $member->id,
            ['name' => $member->name],
            $admin->id
        );
    }

    public function updateMemberModules(
        Workspace $workspace,
        User $admin,
        User $member,
        ?array $modulePermissions,
        bool $restrictAccess = true,
    ): void {
        $this->workspaceContext->ensureCanAssignModulePermissions($admin, $workspace);

        if ($this->isProtectedSuperAdmin($workspace, $member)) {
            throw ValidationException::withMessages([
                'modules' => 'Super Admin accounts always have full access.',
            ]);
        }

        $pivot = $workspace->users()->where('user_id', $member->id)->first()?->pivot;
        if (! $pivot) {
            abort(404);
        }

        $role = $pivot->role ?? null;
        if (! MemberModuleAccess::isConfigurableRole($role)) {
            throw ValidationException::withMessages([
                'modules' => 'Module access can only be configured for Admin, Manager, Team Lead, or Agent accounts.',
            ]);
        }

        if ($role === 'super_admin') {
            throw ValidationException::withMessages([
                'modules' => 'Module access cannot be restricted for Super Admin accounts.',
            ]);
        }

        $modules = $restrictAccess
            ? MemberModuleAccess::sanitizeForRole($role, $modulePermissions ?? [])
            : null;

        foreach ($modules ?? [] as $module) {
            if (MemberModuleAccess::usesPortalModules($role)) {
                continue;
            }

            if (! AdminModules::canGrantModule($module, $admin, $workspace->id)) {
                throw ValidationException::withMessages([
                    'modules' => 'You cannot grant access to '.(AdminModules::labels()[$module] ?? $module).'.',
                ]);
            }
        }

        $workspace->users()->updateExistingPivot($member->id, [
            'module_permissions' => $this->encodeStoredModulePermissions($modules),
        ]);

        $this->syncService->record(
            $workspace,
            'member.modules_updated',
            'user',
            $member->id,
            array_merge($this->memberPayload($member, $workspace), [
                'module_permissions' => $modules,
            ]),
            $admin->id
        );
    }

    protected function modulePermissionsForRoleChange(string $role, User $member, Workspace $workspace): ?string
    {
        if (! MemberModuleAccess::isConfigurableRole($role)) {
            return null;
        }

        if ($role === 'super_admin') {
            return null;
        }

        $existing = $member->getModulePermissions($workspace->id);
        if ($existing !== null) {
            return $this->encodeStoredModulePermissions($existing);
        }

        return null;
    }

    /**
     * @param  list<string>|null  $modulePermissions
     */
    protected function encodeModulePermissions(string $role, ?array $modulePermissions): ?string
    {
        if (! MemberModuleAccess::isConfigurableRole($role)) {
            return null;
        }

        if ($role === 'super_admin') {
            return null;
        }

        if ($modulePermissions === null) {
            return null;
        }

        return $this->encodeStoredModulePermissions(MemberModuleAccess::sanitizeForRole($role, $modulePermissions));
    }

    /**
     * @param  list<string>|null  $modules
     */
    protected function encodeStoredModulePermissions(?array $modules): ?string
    {
        if ($modules === null) {
            return null;
        }

        return json_encode(array_values($modules));
    }

    protected function normalizeRole(string $role): string
    {
        $allowed = array_keys(config('sales_ops.roles', []));

        return in_array($role, $allowed, true) ? $role : 'appointment_setter';
    }

    protected function isProtectedSuperAdmin(Workspace $workspace, User $member): bool
    {
        $pivot = $workspace->users()->where('user_id', $member->id)->first()?->pivot;

        return ($pivot->role ?? null) === 'super_admin';
    }

    protected function ensureMemberIsEditable(Workspace $workspace, User $member, string $field): void
    {
        if (! $this->isProtectedSuperAdmin($workspace, $member)) {
            return;
        }

        $messages = [
            'account' => 'Super Admin accounts cannot be edited.',
            'role' => 'The Super Admin role cannot be changed.',
            'password' => 'Super Admin passwords cannot be changed here.',
            'member' => 'Super Admin accounts cannot be modified.',
        ];

        throw ValidationException::withMessages([
            $field => $messages[$field] ?? 'Super Admin accounts cannot be modified.',
        ]);
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
            'module_permissions' => $member->getModulePermissions($workspace->id),
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
