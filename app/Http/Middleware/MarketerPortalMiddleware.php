<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MarketerPortalMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return redirect()->route('portal.login');
        }

        $user = Auth::user();

        if (! $user->hasActiveMembership()) {
            $fallbackWorkspace = $user->firstActiveWorkspace();

            if ($fallbackWorkspace) {
                $user->update(['current_workspace_id' => $fallbackWorkspace->id]);

                return $next($request);
            }

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
