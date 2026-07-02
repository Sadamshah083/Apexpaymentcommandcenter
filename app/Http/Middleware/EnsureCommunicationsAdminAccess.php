<?php

namespace App\Http\Middleware;

use App\Services\Communications\CommunicationsAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureCommunicationsAdminAccess
{
    public function __construct(
        protected CommunicationsAccessService $access,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $routePrefix = $request->is('admin*') ? 'admin.' : 'portal.';

        if (! $this->access->canConfigure(Auth::user(), $routePrefix)) {
            abort(403, 'You do not have permission to change communications configuration.');
        }

        return $next($request);
    }
}
