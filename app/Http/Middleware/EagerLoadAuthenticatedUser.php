<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EagerLoadAuthenticatedUser
{
    public function handle(Request $request, Closure $next)
    {
        if ($user = $request->user()) {
            $user->loadMissing(['currentWorkspace', 'workspaces']);
        }

        return $next($request);
    }
}
