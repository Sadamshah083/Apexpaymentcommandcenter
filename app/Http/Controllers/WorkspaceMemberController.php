<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceContextService;
use App\Services\Workspace\WorkspaceMemberService;
use App\Support\AdminModules;
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
            "Account \"{$member->name}\" created as ".SalesOps::roleLabel($data['role']).'. They can sign in at the agent portal.',
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
        $validModules = array_keys(AdminModules::all());

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
        if (! SalesOps::isAdminPortalRole($role) || $role === 'super_admin') {
            return null;
        }

        if ($request->input('access_mode', 'full') !== 'restricted') {
            return null;
        }

        return AdminModules::sanitize($request->input('modules', []));
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
