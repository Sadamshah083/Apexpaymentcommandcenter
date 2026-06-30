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
            return redirect()->route('admin.login');
        }

        $user = Auth::user();

        if (! $user->canAccessAdminPortal()) {
            Auth::logout();

            return redirect()->route('admin.login')->withErrors([
                'username' => 'Access denied. Admin portal requires Super Admin or Admin role.',
            ]);
        }

        $user->ensureAdminPortalWorkspace();

        return $next($request);
    }
}
