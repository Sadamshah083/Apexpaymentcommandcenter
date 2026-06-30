<?php

namespace App\Http\Middleware;

use App\Support\AdminModules;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureAdminModuleAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        $workspaceId = $user?->current_workspace_id;

        if (! $user || ! $workspaceId) {
            abort(403);
        }

        $module = AdminModules::moduleForRoute($request->route()?->getName());

        if ($module && ! $user->canAccessAdminModule($module, $workspaceId)) {
            abort(403, 'You do not have access to this feature.');
        }

        return $next($request);
    }
}
