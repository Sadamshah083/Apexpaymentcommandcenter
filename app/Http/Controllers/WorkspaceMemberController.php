<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceContextService;
use App\Services\Workspace\WorkspaceMemberService;
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
        $data = $request->validate([
            'username' => 'required|string|max:255',
            'password' => 'required|string|min:6|confirmed',
            'role' => 'required|in:admin,sdr,marketer,account_executive,data_acquisition',
        ]);

        $member = $this->memberService->createAgent(
            $workspace,
            Auth::user(),
            $data['username'],
            $data['password'],
            $data['role'],
        );

        $portalLabel = $data['role'] === 'admin'
            ? 'Admin and Marketer portals'
            : 'Agent portal only';

        return $this->respond(
            $request,
            "Account \"{$member->name}\" created. Sign in at the agent portal ({$portalLabel}).",
        );
    }

    public function updateRole(Request $request, Workspace $workspace, User $member)
    {
        $data = $request->validate([
            'role' => 'required|in:admin,sdr,marketer,account_executive,data_acquisition',
        ]);

        $this->memberService->updateMemberRole($workspace, Auth::user(), $member, $data['role']);

        return $this->respond($request, "Updated role for {$member->name}.");
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
