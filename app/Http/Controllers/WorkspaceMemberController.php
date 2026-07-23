<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceContextService;
use App\Services\Workspace\WorkspaceMemberService;
use App\Support\AdminModules;
use App\Support\MemberModuleAccess;
use App\Support\SalesOps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WorkspaceMemberController extends Controller
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
        protected WorkspaceMemberService $memberService,
    ) {}

    public function store(Request $request, Workspace $workspace)
    {
        $creatableRoles = array_keys(SalesOps::creatableAgentRoles());
        if (! Auth::user()->isPlatformSuperAdmin()) {
            $creatableRoles = array_values(array_filter(
                $creatableRoles,
                static fn (string $role): bool => $role !== 'admin'
            ));
        }

        $data = $request->validate([
            'username' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'role' => 'required|in:'.implode(',', $creatableRoles),
            'team_lead_user_id' => ['nullable', 'integer'],
            'campaign_id' => ['nullable', 'integer'],
            'extension_num' => ['nullable', 'string', 'max:32'],
            'caller_id_num' => ['nullable', 'string', 'max:32'],
            'access_mode' => 'sometimes|in:full,restricted',
            'modules' => 'nullable|array',
            'modules.*' => 'string|in:'.implode(',', array_keys(AdminModules::all())),
        ], [
            'email.unique' => 'This email is already used. Admin and agent accounts must each have a different email.',
            'email.required' => 'Email is required and must be unique.',
        ]);

        // Force corporate domain (never apexpayments.com).
        $local = strtolower((string) strstr($data['email'], '@', true));
        // Guard pasted full emails / spaces in the local part from the popup.
        if (str_contains($local, '@')) {
            $local = (string) strstr($local, '@', true);
        }
        $local = preg_replace('/[^a-z0-9._+-]/', '', $local) ?: strtolower(preg_replace('/\s+/', '', $data['username']));
        $local = preg_replace('/[^a-z0-9._+-]/', '', (string) $local) ?: 'agent';
        $data['email'] = $local.'@apexonepayments.com';

        if (User::where('email', $data['email'])->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => 'This email is already used. Admin and agent accounts must each have a different email.',
            ]);
        }

        $modulePermissions = $this->modulePermissionsFromRequest($request, $data['role']);

        $teamLeadUserId = isset($data['team_lead_user_id']) && (int) $data['team_lead_user_id'] > 0
            ? (int) $data['team_lead_user_id']
            : null;
        $campaignId = isset($data['campaign_id']) && (int) $data['campaign_id'] > 0
            ? (int) $data['campaign_id']
            : null;

        try {
            $member = $this->memberService->createAgent(
                $workspace,
                Auth::user(),
                $data['username'],
                $data['password'],
                $data['role'],
                $modulePermissions,
                $teamLeadUserId,
                $campaignId,
                $data['email'],
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'username' => 'Could not create account: '.$e->getMessage(),
            ]);
        }

        $phoneNote = '';
        $extensionNum = trim((string) ($data['extension_num'] ?? ''));
        $callerIdNum = preg_replace('/\D/', '', (string) ($data['caller_id_num'] ?? ''));
        if ($extensionNum !== '') {
            try {
                $agentService = app(\App\Services\Communications\CommunicationsAgentService::class);
                $result = $agentService->provision($workspace, $member, [
                    'extension_num' => $extensionNum,
                    // Login password stays as entered; SIP is padded to Morpheus 8-char minimum.
                    'sip_password' => $agentService->ensureSipPassword($data['password']),
                    'caller_id_name' => $data['username'],
                    'caller_id_num' => $callerIdNum !== '' ? $callerIdNum : null,
                    'create_morpheus_user' => true,
                ]);
                $phoneNote = ($result['ok'] ?? false)
                    ? ' '.($result['message'] ?? "Phone line {$extensionNum} provisioned.")
                    : ' Account created, but phone line could not be provisioned: '.($result['error'] ?? 'unknown error');
            } catch (\Throwable $e) {
                $phoneNote = ' Account created, but phone line provisioning failed: '.$e->getMessage();
            }
        }

        return $this->respond(
            $request,
            SalesOps::isAdminPortalRole($data['role'])
                ? "Account \"{$member->name}\" created as ".SalesOps::roleLabel($data['role']).'. They can sign in at the admin portal.'.$phoneNote
                : "Account \"{$member->name}\" created as ".SalesOps::roleLabel($data['role']).'. They can sign in at the agent portal.'.$phoneNote,
            [
                'member' => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $data['role'],
                ],
            ],
        );
    }

    public function updateRole(Request $request, Workspace $workspace, User $member)
    {
        $assignableRoles = array_keys(SalesOps::assignableMemberRoles());

        $data = $request->validate([
            'role' => 'required|in:'.implode(',', $assignableRoles),
        ]);

        $this->memberService->updateMemberRole($workspace, Auth::user(), $member, $data['role']);

        return $this->respond($request, "Updated {$member->name}'s role to ".SalesOps::roleLabel($data['role']).'.');
    }

    public function updateTeamLead(Request $request, Workspace $workspace, User $member)
    {
        $data = $request->validate([
            'team_lead_user_id' => ['nullable', 'integer'],
        ]);

        $teamLeadUserId = isset($data['team_lead_user_id']) && (int) $data['team_lead_user_id'] > 0
            ? (int) $data['team_lead_user_id']
            : null;

        $this->memberService->updateMemberTeamLead($workspace, Auth::user(), $member, $teamLeadUserId);

        if ($teamLeadUserId === null) {
            return $this->respond($request, "{$member->name} is unassigned from a team.");
        }

        $lead = $workspace->users()->where('user_id', $teamLeadUserId)->first();

        return $this->respond(
            $request,
            "Assigned {$member->name} to ".($lead?->name ?? 'team')."'s team.",
        );
    }

    public function updateCampaign(Request $request, Workspace $workspace, User $member)
    {
        $data = $request->validate([
            'campaign_id' => ['nullable', 'integer'],
        ]);

        $campaignId = isset($data['campaign_id']) && (int) $data['campaign_id'] > 0
            ? (int) $data['campaign_id']
            : null;

        $this->memberService->updateMemberCampaign($workspace, Auth::user(), $member, $campaignId);

        if ($campaignId === null) {
            return $this->respond($request, "{$member->name} has no campaign assigned.");
        }

        $campaign = \App\Models\LeadCampaign::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $campaignId)
            ->first();

        return $this->respond(
            $request,
            "Assigned campaign \"".($campaign?->name ?? 'Campaign')."\" to {$member->name}. Their team members inherit it.",
        );
    }

    public function update(Request $request, Workspace $workspace, User $member)
    {
        $assignableRoles = array_keys(SalesOps::assignableMemberRoles());

        $request->merge([
            'campaign_id' => $request->filled('campaign_id') ? $request->input('campaign_id') : null,
            'team_lead_user_id' => $request->filled('team_lead_user_id') ? $request->input('team_lead_user_id') : null,
            'role' => $request->filled('role') ? $request->input('role') : null,
        ]);

        $data = $request->validate([
            'username' => 'required|string|max:255|unique:users,name,'.$member->id,
            'email' => 'required|email|max:255|unique:users,email,'.$member->id,
            'password' => 'nullable|string|min:6|confirmed',
            'role' => 'nullable|in:'.implode(',', $assignableRoles),
            'team_lead_user_id' => ['nullable', 'integer'],
            'campaign_id' => ['nullable', 'integer'],
        ], [
            'email.unique' => 'This email is already used. Admin and agent accounts must each have a different email.',
            'username.unique' => 'This username is already taken.',
        ]);

        $member = $this->memberService->updateMemberProfile(
            $workspace,
            Auth::user(),
            $member,
            $data['username'],
            $data['email'],
            $data['password'] ?? null,
        );

        $isProtectedSuperAdmin = ($workspace->users()->where('user_id', $member->id)->first()?->pivot?->role ?? null) === 'super_admin';

        if (! $isProtectedSuperAdmin && ! empty($data['role'])) {
            $this->memberService->updateMemberRole($workspace, Auth::user(), $member, $data['role']);
        }

        $role = (string) ($workspace->users()->where('user_id', $member->id)->first()?->pivot?->role ?? '');

        // Always honor campaign / team-lead fields from Edit when they apply to the final role.
        if (SalesOps::isTeamLeadRole($role)) {
            $campaignId = $request->filled('campaign_id') ? (int) $request->input('campaign_id') : null;
            if ($campaignId === 0) {
                $campaignId = null;
            }
            // Only update when the field was present (enabled in the edit form).
            if ($request->exists('campaign_id') || array_key_exists('campaign_id', $request->all())) {
                $this->memberService->updateMemberCampaign($workspace, Auth::user(), $member, $campaignId);
            }
        }

        if (SalesOps::isAgentRole($role)) {
            $teamLeadUserId = $request->filled('team_lead_user_id') ? (int) $request->input('team_lead_user_id') : null;
            if ($teamLeadUserId === 0) {
                $teamLeadUserId = null;
            }
            if ($request->exists('team_lead_user_id') || array_key_exists('team_lead_user_id', $request->all())) {
                $this->memberService->updateMemberTeamLead($workspace, Auth::user(), $member, $teamLeadUserId);
            }
        }

        return $this->respond($request, "Updated account for {$member->name}.");
    }

    public function resetPassword(Request $request, Workspace $workspace, User $member)
    {
        $data = $request->validate([
            'password' => 'required|string|min:6|confirmed',
        ]);

        $this->memberService->resetMemberPassword($workspace, Auth::user(), $member, $data['password']);

        return $this->respond($request, "Password reset for {$member->name}.");
    }

    public function suspend(Request $request, Workspace $workspace, User $member)
    {
        $this->memberService->suspendMember($workspace, Auth::user(), $member);

        return $this->respond($request, "{$member->name} has been suspended.");
    }

    public function reactivate(Request $request, Workspace $workspace, User $member)
    {
        $this->memberService->reactivateMember($workspace, Auth::user(), $member);

        return $this->respond($request, "{$member->name} has been reactivated.");
    }

    public function destroy(Request $request, Workspace $workspace, User $member)
    {
        $this->memberService->removeMember($workspace, Auth::user(), $member);

        return $this->respond($request, "{$member->name} removed from workspace.");
    }

    public function updateModules(Request $request, Workspace $workspace, User $member)
    {
        $pivot = $workspace->users()->where('user_id', $member->id)->first()?->pivot;
        $memberRole = $pivot->role ?? 'appointment_setter';
        $validModules = MemberModuleAccess::validKeysForRole($memberRole);

        $data = $request->validate([
            'modules' => 'nullable|array',
            'modules.*' => 'string|in:'.implode(',', $validModules),
            'access_mode' => 'required|in:full,restricted',
        ]);

        $this->memberService->updateMemberModules(
            $workspace,
            Auth::user(),
            $member,
            $data['modules'] ?? [],
            $data['access_mode'] === 'restricted',
        );

        return $this->respond($request, "Updated module access for {$member->name}.");
    }

    /**
     * @return list<string>|null
     */
    protected function modulePermissionsFromRequest(Request $request, string $role): ?array
    {
        if (! MemberModuleAccess::isConfigurableRole($role) || $role === 'super_admin') {
            return null;
        }

        if ($request->input('access_mode', 'full') !== 'restricted') {
            return null;
        }

        return MemberModuleAccess::sanitizeForRole($role, $request->input('modules', []));
    }

    protected function respond(Request $request, string $message, array $extra = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(array_merge([
                'success' => true,
                'ok' => true,
                'message' => $message,
            ], $extra));
        }

        return back()->with('success', $message);
    }
}
