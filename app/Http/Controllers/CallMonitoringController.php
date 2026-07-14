<?php

namespace App\Http\Controllers;

use App\Services\Communications\AgentPresenceService;
use App\Services\Communications\CallMonitoringService;
use App\Services\Communications\CommunicationsAccessService;
use App\Services\Communications\MorpheusCallEventService;
use App\Services\Workspace\WorkspaceContextService;
use App\Support\ReleaseSessionLock;
use Illuminate\Http\Request;

class CallMonitoringController extends Controller
{
    public function __construct(
        protected CallMonitoringService $monitoring,
        protected CommunicationsAccessService $access,
        protected WorkspaceContextService $workspaceContext,
        protected MorpheusCallEventService $callEvents,
        protected AgentPresenceService $presence,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $routePrefix = $this->routePrefix();

        if (! $this->access->canViewCallMonitoring($user, $routePrefix)) {
            abort(403, 'Call monitoring is not available for this account.');
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        // Instant first paint — never wait on Morpheus HTTP for the HTML response.
        $snapshot = $this->monitoring->snapshot($workspace, light: true);
        $pollUrl = route($routePrefix.'communications.monitoring.live');
        $streamUrl = route($routePrefix.'communications.monitoring.stream');

        $view = $routePrefix === 'admin.'
            ? 'communications.monitoring.index'
            : 'communications.monitoring.portal';

        return view($view, [
            'routePrefix' => $routePrefix,
            'snapshot' => $snapshot,
            'pollUrl' => $pollUrl,
            'streamUrl' => $streamUrl,
            'hubAccess' => $this->access->viewMeta($user, $routePrefix),
        ]);
    }

    public function live(Request $request)
    {
        $user = $request->user();
        $routePrefix = $this->routePrefix();

        if (! $this->access->canViewCallMonitoring($user, $routePrefix)) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        // Critical: unlock session so Turbo navigation is not blocked by live polls.
        ReleaseSessionLock::now($request);

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        // Default light. Optional full=1 does a fast Morpheus /calls probe (2s max).
        $full = $request->boolean('full');

        return response()->json($this->monitoring->snapshot($workspace, light: ! $full));
    }

    /**
     * Dialer heartbeat so Monitoring can show Not-in-call + Auto/Manual mode.
     */
    public function presenceHeartbeat(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 401);
        }

        ReleaseSessionLock::now($request);

        $validated = $request->validate([
            'dial_mode' => ['nullable', 'string', 'in:auto,manual'],
            'auto_session_active' => ['nullable', 'boolean'],
            'auto_paused' => ['nullable', 'boolean'],
            'on_call' => ['nullable', 'boolean'],
            'extension' => ['nullable', 'string', 'max:32'],
            'call_ended' => ['nullable', 'boolean'],
        ]);

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            return response()->json(['ok' => false, 'message' => 'No workspace'], 422);
        }

        $membership = $workspace->users()->where('users.id', $user->id)->first();
        $role = (string) ($membership?->pivot?->role ?? '');
        $extension = preg_replace('/\D/', '', (string) ($validated['extension'] ?? $membership?->pivot?->morpheus_extension_num ?? '')) ?: null;

        if (! empty($validated['call_ended'])) {
            $this->presence->markCallEnded($user, $workspace);
        }

        $payload = [
            'auto_session_active' => (bool) ($validated['auto_session_active'] ?? false),
            'auto_paused' => (bool) ($validated['auto_paused'] ?? false),
            'on_call' => ! empty($validated['call_ended'])
                ? false
                : (bool) ($validated['on_call'] ?? false),
            'extension' => $extension,
            'role' => $role,
            'role_label' => \App\Support\SalesOps::roleLabel($role),
            'name' => $user->name,
        ];
        if (array_key_exists('dial_mode', $validated) && filled($validated['dial_mode'])) {
            $payload['dial_mode'] = $validated['dial_mode'];
        }

        $entry = $this->presence->heartbeat($user, $workspace, $payload);

        return response()->json(['ok' => true, 'presence' => $entry]);
    }

    /**
     * Real-time wallboard push (SSE) — updates as soon as call state changes.
     */
    public function stream(Request $request)
    {
        $user = $request->user();
        $routePrefix = $this->routePrefix();

        if (! $this->access->canViewCallMonitoring($user, $routePrefix)) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        // Unlock before the long-lived stream or every sidebar click waits on this worker.
        ReleaseSessionLock::now($request);

        return response()->stream(function () use ($workspace) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @ob_implicit_flush(true);

            echo ": connected\n\n";
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();

            $lastVersion = -1;
            $lastFingerprint = '';
            $idleTicks = 0;
            // Short cap: clients now prefer JSON polls. Do not pin FPM workers for hours.
            $maxIdle = 120;
            $ticksSinceFull = 0;

            while (! connection_aborted() && $idleTicks < $maxIdle) {
                try {
                    $version = $this->callEvents->monitoringVersion();
                    $versionChanged = $version !== $lastVersion;
                    // Full Morpheus probe rarely; light cache-based snapshot on most ticks.
                    $shouldPush = $versionChanged || ($idleTicks % 8) === 0;

                    if ($shouldPush) {
                        $useFull = $versionChanged || $ticksSinceFull >= 40;
                        $snapshot = $this->monitoring->snapshot(
                            $workspace,
                            light: ! $useFull,
                            probeConnected: false,
                        );
                        if ($useFull) {
                            $ticksSinceFull = 0;
                        } else {
                            $ticksSinceFull++;
                        }

                        $fingerprint = md5(json_encode([
                            $snapshot['version'] ?? 0,
                            $snapshot['summary'] ?? [],
                            collect($snapshot['rows'] ?? [])->map(fn ($row) => [
                                $row['id'] ?? null,
                                $row['status_group'] ?? null,
                                $row['bucket'] ?? null,
                                $row['connected_at'] ?? null,
                                $row['timer_sec'] ?? null,
                            ])->all(),
                        ]));

                        if ($fingerprint !== $lastFingerprint || $versionChanged) {
                            $lastFingerprint = $fingerprint;
                            $lastVersion = $version;
                            echo 'data: '.json_encode($snapshot, JSON_THROW_ON_ERROR)."\n\n";
                            if (function_exists('ob_flush')) {
                                @ob_flush();
                            }
                            flush();
                            $idleTicks = 0;
                        } else {
                            $idleTicks++;
                        }
                    } else {
                        $idleTicks++;
                        $ticksSinceFull++;
                    }
                } catch (\Throwable) {
                    $idleTicks++;
                    $ticksSinceFull++;
                }

                // Keep the SSE socket alive under nginx/php-fpm read timeouts.
                if (($idleTicks % 15) === 0) {
                    echo ": ping\n\n";
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    flush();
                }

                usleep(300000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    protected function routePrefix(): string
    {
        return request()->is('admin*') ? 'admin.' : 'portal.';
    }
}
