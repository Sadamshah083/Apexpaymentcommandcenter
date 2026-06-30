<?php

namespace App\Http\Controllers;

use App\Jobs\PollInboundMailboxJob;
use App\Jobs\RunDomainAuthCheckJob;
use App\Models\DeliverabilityTest;
use App\Models\InboundTestInbox;
use App\Services\Deliverability\DeliverabilityAnalyzer;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeliverabilityController extends Controller
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
    ) {}

    public function index()
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        $tests = DeliverabilityTest::query()
            ->where('workspace_id', $workspace->id)
            ->latest()
            ->paginate(10);

        $inboxes = InboundTestInbox::latest()->take(5)->get();
        $inboundDomainConfigured = filled(config('email_checker.inbound.domain'));
        $inboundImapConfigured = filled(config('email_checker.inbound.imap_host'));

        return view('deliverability.index', compact('tests', 'inboxes', 'inboundDomainConfigured', 'inboundImapConfigured'));
    }

    public function store(Request $request)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        $request->validate([
            'domain' => 'required|string|max:255',
            'sending_ip' => 'nullable|ip',
            'dkim_selector' => 'nullable|string|max:100',
            'run_sync' => 'nullable|boolean',
        ]);

        $test = DeliverabilityTest::create([
            'workspace_id' => $workspace->id,
            'user_id' => Auth::id(),
            'domain' => strtolower($request->domain),
            'sending_ip' => $request->sending_ip,
            'dkim_selector' => $request->dkim_selector ?: 'default',
            'status' => 'pending',
        ]);

        if ($request->boolean('run_sync')) {
            RunDomainAuthCheckJob::dispatchSync($test->id);
        } else {
            RunDomainAuthCheckJob::dispatch($test->id);
        }

        return redirect()->route(request()->is('admin*') ? 'admin.deliverability.show' : 'portal.deliverability.show', $test)
            ->with('success', 'Deliverability check started.');
    }

    public function show(DeliverabilityTest $deliverability)
    {
        $this->ensureTestBelongsToWorkspace($deliverability);

        return view('deliverability.show', ['test' => $deliverability]);
    }

    public function status(DeliverabilityTest $deliverability)
    {
        $this->ensureTestBelongsToWorkspace($deliverability);

        return response()->json([
            'status' => $deliverability->status,
            'overall_score' => $deliverability->overall_score,
            'complete' => in_array($deliverability->status, ['completed', 'failed'], true),
        ]);
    }

    public function quickCheck(Request $request, DeliverabilityAnalyzer $analyzer)
    {
        $request->validate([
            'domain' => 'required|string|max:255',
            'sending_ip' => 'nullable|ip',
        ]);

        $result = $analyzer->analyze(
            strtolower($request->domain),
            $request->sending_ip,
            $request->dkim_selector,
        );

        return response()->json($result);
    }

    public function createInbox()
    {
        $domain = config('email_checker.inbound.domain');
        $route = request()->is('admin*') ? 'admin.deliverability.index' : 'portal.deliverability.index';

        if (! $domain) {
            return redirect()
                ->route($route)
                ->with('error', 'Inbound test inbox requires EMAIL_CHECKER_INBOUND_DOMAIN in your environment.');
        }

        $inbox = InboundTestInbox::createInbox();

        if (config('email_checker.inbound.imap_host')) {
            PollInboundMailboxJob::dispatch();
        }

        return redirect()
            ->route($route)
            ->with('success', "Test inbox created. Send your campaign email to {$inbox->email_address} — polling runs every 5 minutes when IMAP is configured.");
    }

    public function inboxStatus(InboundTestInbox $inbox)
    {
        return response()->json([
            'status' => $inbox->status,
            'email_address' => $inbox->email_address,
            'overall_score' => $inbox->overall_score,
            'auth_results' => $inbox->auth_results,
            'complete' => in_array($inbox->status, ['analyzed', 'expired'], true),
            'expires_at' => $inbox->expires_at?->toIso8601String(),
        ]);
    }

    protected function ensureTestBelongsToWorkspace(DeliverabilityTest $test): void
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        if ($test->workspace_id && $test->workspace_id !== $workspace->id) {
            abort(404);
        }
    }
}
