<?php

namespace App\Http\Middleware;

use App\Services\Workspace\WorkspaceContextService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MarketerPortalMiddleware
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        if (! Auth::check()) {
            return redirect()->route('portal.login');
        }

        $user = Auth::user();

        if (! $user->canAccessPortal()) {
            if ($user->canAccessAdminPortal()) {
                return redirect()->route('admin.dashboard');
            }

            Auth::logout();

            return redirect()->route('portal.login')->withErrors([
                'username' => 'Access denied. This account is not assigned to a portal role.',
            ]);
        }

        if (! $user->hasActiveMembership()) {
            $fallbackWorkspace = $user->firstActiveWorkspace();

            if ($fallbackWorkspace) {
                $this->workspaceContext->ensureUserIsMember($user, $fallbackWorkspace);
                $user->update(['current_workspace_id' => $fallbackWorkspace->id]);
                $user->refresh();
            }
        }

        if (! $user->canAccessPortal($user->current_workspace_id)) {
            Auth::logout();

            return redirect()->route('portal.login')->withErrors([
                'username' => $user->hasAnySuspendedMembership()
                    ? 'Your account has been suspended. Contact your workspace administrator.'
                    : 'Access denied. Workspace membership required for this portal.',
            ]);
        }

        return $next($request);
    }
}
