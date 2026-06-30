<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        $workspaceId = $user?->current_workspace_id;

        if (! $user || ! $workspaceId || ! $user->isSuperAdmin($workspaceId)) {
            abort(403, 'Only the workspace Super Admin can manage user accounts.');
        }

        return $next($request);
    }
}
