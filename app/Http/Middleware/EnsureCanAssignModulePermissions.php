<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureCanAssignModulePermissions
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        $workspace = $request->route('workspace');

        if (! $user || ! $workspace || ! $user->canAssignModulePermissions($workspace->id)) {
            abort(403, 'You do not have permission to manage module access.');
        }

        return $next($request);
    }
}
