<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureCanManageMembers
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        $workspaceId = $user?->current_workspace_id;

        if (! $user || ! $workspaceId || ! $user->canManageWorkspaceMembers($workspaceId)) {
            abort(403, 'Only Super Admin or Admin can manage user accounts.');
        }

        return $next($request);
    }
}
