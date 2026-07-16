<?php

namespace App\Http\Controllers;

use App\Services\Communications\AgentBreakService;
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
        protected AgentBreakService $breaks,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $routePrefix = $this->routePrefix();

        if (! $this->access->canViewCallMonitoring($user, $routePrefix)) {
            abort(403, 'Call monitoring is not available for this account.');
        }

        $workspace = $this->monitoring->resolveWorkspaceForMonitoring($user);
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

        $workspace = $this->monitoring->resolveWorkspaceForMonitoring($user);
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
            'in_disposition' => ['nullable', 'boolean'],
            'disposition_phone' => ['nullable', 'string', 'max:32'],
            'extension' => ['nullable', 'string', 'max:32'],
            'call_ended' => ['nullable', 'boolean'],
            'break_status' => ['nullable', 'string', 'in:none,break,lunch'],
            'break_ends_at' => ['nullable', 'string', 'max:64'],
        ]);

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            return response()->json(['ok' => false, 'message' => 'No workspace'], 422);
        }

        $membership = $workspace->users()->where('users.id', $user->id)->first();
        $role = (string) ($membership?->pivot?->role ?? '');
        $extension = preg_replace('/\D/', '', (string) ($validated['extension'] ?? $membership?->pivot?->morpheus_extension_num ?? '')) ?: null;

        // Super Admin / Admin (and other non-agent roles) never belong on Call Monitoring.
        if (
            AgentPresenceService::isExcludedFromMonitoring(
                $role,
                \App\Support\SalesOps::roleLabel($role),
                $user,
                (int) $workspace->id,
            )
        ) {
            $this->presence->forgetUser($workspace, (int) $user->id);

            return response()->json(['ok' => true, 'presence' => ['ignored' => true, 'role' => $role]]);
        }

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
        if (array_key_exists('in_disposition', $validated)) {
            $payload['in_disposition'] = (bool) $validated['in_disposition'];
        } elseif (! empty($validated['call_ended'])) {
            $payload['in_disposition'] = true;
        }
        if (array_key_exists('disposition_phone', $validated) && filled($validated['disposition_phone'])) {
            $payload['disposition_phone'] = $validated['disposition_phone'];
        }
        if (array_key_exists('break_status', $validated) && filled($validated['break_status'])) {
            $payload['break_status'] = $validated['break_status'];
        }
        if (array_key_exists('break_ends_at', $validated) && filled($validated['break_ends_at'])) {
            $payload['break_ends_at'] = $validated['break_ends_at'];
        }

        $entry = $this->presence->heartbeat($user, $workspace, $payload);

        return response()->json(['ok' => true, 'presence' => $entry]);
    }

    /**
     * Start break (5m) or lunch (30m). Persisted in DB for Call Monitoring.
     */
    public function breakStart(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 401);
        }

        $routePrefix = $this->routePrefix();
        if (! $this->access->isAgentTier($user, $routePrefix)) {
            return response()->json(['ok' => false, 'message' => 'Break/lunch is only for agents and team leads.'], 403);
        }

        ReleaseSessionLock::now($request);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:break,lunch'],
            'extension' => ['nullable', 'string', 'max:32'],
            'dial_mode' => ['nullable', 'string', 'in:auto,manual'],
            'auto_session_active' => ['nullable', 'boolean'],
        ]);

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            return response()->json(['ok' => false, 'message' => 'No workspace'], 422);
        }

        $result = $this->breaks->start($workspace, $user, $validated['type'], [
            'extension' => $validated['extension'] ?? null,
            'dial_mode' => $validated['dial_mode'] ?? null,
            'auto_session_active' => (bool) ($validated['auto_session_active'] ?? false),
        ]);

        $status = ($result['ok'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }

    /**
     * End the current break/lunch early (Break Out / End Lunch).
     */
    public function breakEnd(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 401);
        }

        $routePrefix = $this->routePrefix();
        if (! $this->access->isAgentTier($user, $routePrefix)) {
            return response()->json(['ok' => false, 'message' => 'Break/lunch is only for agents and team leads.'], 403);
        }

        ReleaseSessionLock::now($request);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'in:manual,auto'],
        ]);

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            return response()->json(['ok' => false, 'message' => 'No workspace'], 422);
        }

        $result = $this->breaks->end($workspace, $user, $validated['reason'] ?? 'manual');

        return response()->json($result);
    }

    /**
     * Current break/lunch status for the logged-in agent/team lead.
     */
    public function breakStatus(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 401);
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            return response()->json(['ok' => true, 'session' => null]);
        }

        $session = $this->breaks->activeForUser($workspace, $user);

        return response()->json([
            'ok' => true,
            'session' => $session ? $this->breaks->toArray($session) : null,
        ]);
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

        $workspace = $this->monitoring->resolveWorkspaceForMonitoring($user);
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
            $lastPresenceVersion = -1;
            $lastFingerprint = '';
            $idleTicks = 0;
            // Keep stream open longer so login/idle flips push in real time.
            $maxIdle = 600;
            $ticksSinceFull = 0;
            $presence = app(AgentPresenceService::class);
            $workspaceId = (int) ($workspace?->id ?? 0);

            while (! connection_aborted() && $idleTicks < $maxIdle) {
                try {
                    $version = $this->callEvents->monitoringVersion();
                    $presenceVersion = $workspaceId > 0 ? $presence->presenceVersion($workspaceId) : 0;
                    $versionChanged = $version !== $lastVersion || $presenceVersion !== $lastPresenceVersion;
                    // Push on presence/login changes immediately; otherwise light refresh.
                    $shouldPush = $versionChanged || ($idleTicks % 5) === 0;

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
                            $snapshot['presence_version'] ?? 0,
                            $snapshot['summary'] ?? [],
                            collect($snapshot['rows'] ?? [])->map(fn ($row) => [
                                $row['id'] ?? null,
                                $row['status_group'] ?? null,
                                $row['bucket'] ?? null,
                                $row['connected_at'] ?? null,
                            ])->all(),
                            collect($snapshot['tables']['not_in_call'] ?? [])->map(fn ($row) => [
                                $row['id'] ?? null,
                                $row['dial_mode'] ?? null,
                            ])->all(),
                            collect($snapshot['tables']['disposition'] ?? [])->map(fn ($row) => $row['id'] ?? null)->all(),
                            collect($snapshot['tables']['break'] ?? [])->map(fn ($row) => [
                                $row['id'] ?? null,
                                $row['timer_sec'] ?? null,
                            ])->all(),
                            collect($snapshot['tables']['lunch'] ?? [])->map(fn ($row) => [
                                $row['id'] ?? null,
                                $row['timer_sec'] ?? null,
                            ])->all(),
                            collect($snapshot['tables']['not_logged_in'] ?? [])->map(fn ($row) => $row['id'] ?? null)->all(),
                        ]));

                        if ($fingerprint !== $lastFingerprint || $versionChanged) {
                            $lastFingerprint = $fingerprint;
                            $lastVersion = $version;
                            $lastPresenceVersion = $presenceVersion;
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

                usleep(250000);
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
