<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminPortalMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return redirect()->route('admin.login');
        }

        $user = Auth::user();
        if (! $user->isWorkspaceAdmin()) {
            Auth::logout();
            return redirect()->route('admin.login')->withErrors(['username' => 'Access denied. Administrator privileges required for this portal.']);
        }

        return $next($request);
    }
}
