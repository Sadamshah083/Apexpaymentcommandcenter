<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminPortalMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (! Auth::check()) {
            return redirect()->guest(route('admin.login'));
        }

        $user = Auth::user();
        $user->loadMissing(['workspaces']);

        if (! $user->canAccessAdminPortal()) {
            if ($user->canAccessPortal()) {
                return redirect()->route('portal.dashboard');
            }

            Auth::logout();

            return redirect()->route('admin.login')->withErrors([
                'username' => 'Access denied. Admin portal requires Super Admin or Admin role.',
            ]);
        }

        $user->ensureAdminPortalWorkspace();

        return $next($request);
    }
}
