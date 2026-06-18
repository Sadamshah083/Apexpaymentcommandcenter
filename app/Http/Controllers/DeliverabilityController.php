<?php

namespace App\Http\Controllers;

use App\Jobs\RunDomainAuthCheckJob;
use App\Models\DeliverabilityTest;
use App\Models\InboundTestInbox;
use App\Services\Deliverability\DeliverabilityAnalyzer;
use Illuminate\Http\Request;

class DeliverabilityController extends Controller
{
    public function index()
    {
        $tests = DeliverabilityTest::latest()->paginate(10);
        $inboxes = InboundTestInbox::latest()->take(5)->get();

        return view('deliverability.index', compact('tests', 'inboxes'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'domain' => 'required|string|max:255',
            'sending_ip' => 'nullable|ip',
            'dkim_selector' => 'nullable|string|max:100',
            'run_sync' => 'nullable|boolean',
        ]);

        $test = DeliverabilityTest::create([
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
        return view('deliverability.show', ['test' => $deliverability]);
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
        $inbox = InboundTestInbox::createInbox();

        return redirect()->route(request()->is('admin*') ? 'admin.deliverability.index' : 'portal.deliverability.index')
            ->with('success', "Test inbox created: {$inbox->email_address}. Send your email to this address (Phase 2: IMAP analysis).");
    }
}
