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

        $data = $request->validate([
            'username' => 'required|string|max:255',
            'password' => 'required|string|min:6|confirmed',
            'role' => 'required|in:'.implode(',', $creatableRoles),
            'access_mode' => 'sometimes|in:full,restricted',
            'modules' => 'nullable|array',
            'modules.*' => 'string|in:'.implode(',', array_keys(AdminModules::all())),
        ]);

        $modulePermissions = $this->modulePermissionsFromRequest($request, $data['role']);

        $member = $this->memberService->createAgent(
            $workspace,
            Auth::user(),
            $data['username'],
            $data['password'],
            $data['role'],
            $modulePermissions,
        );

        return $this->respond(
            $request,
            SalesOps::isAdminPortalRole($data['role'])
                ? "Account \"{$member->name}\" created as ".SalesOps::roleLabel($data['role']).'. They can sign in at the admin portal.'
                : "Account \"{$member->name}\" created as ".SalesOps::roleLabel($data['role']).'. They can sign in at the agent portal.',
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
        ]);

        $member = $this->memberService->updateMemberProfile(
            $workspace,
            Auth::user(),
            $member,
            $data['username'],
            $data['email'],
            $data['password'] ?? null,
        );

        if (! empty($data['role'])) {
            $this->memberService->updateMemberRole($workspace, Auth::user(), $member, $data['role']);
        }

        $role = (string) ($workspace->users()->where('user_id', $member->id)->first()?->pivot?->role ?? '');

        if (SalesOps::isTeamLeadRole($role) && $request->exists('campaign_id')) {
            $campaignId = isset($data['campaign_id']) && (int) $data['campaign_id'] > 0
                ? (int) $data['campaign_id']
                : null;
            $this->memberService->updateMemberCampaign($workspace, Auth::user(), $member, $campaignId);
        }

        if (SalesOps::isAgentRole($role) && $request->exists('team_lead_user_id')) {
            $teamLeadUserId = isset($data['team_lead_user_id']) && (int) $data['team_lead_user_id'] > 0
                ? (int) $data['team_lead_user_id']
                : null;
            $this->memberService->updateMemberTeamLead($workspace, Auth::user(), $member, $teamLeadUserId);
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

    protected function respond(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
            ]);
        }

        return back()->with('success', $message);
    }
}
