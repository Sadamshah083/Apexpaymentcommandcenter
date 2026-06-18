@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Deliverability')

@section('content')
<div class="mb-8">
    <h2 class="text-2xl font-bold">Deliverability Checker</h2>
    <p class="text-slate-600">Test SPF, DKIM, DMARC, MX, PTR, and DNS blocklists — like mail-tester.com DNS checks.</p>
</div>

<div class="grid md:grid-cols-2 gap-6 mb-8">
    <form action="{{ request()->is('admin*') ? route('admin.deliverability.store') : route('portal.deliverability.store') }}" method="POST" class="bg-white rounded-xl shadow-sm border p-6 space-y-4">
        @csrf
        <h3 class="font-semibold">Domain Authentication Test</h3>
        <div>
            <label class="block text-sm font-medium mb-1">Sending Domain</label>
            <input type="text" name="domain" placeholder="yourdomain.com" required class="w-full border rounded-lg px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Sending IP (optional)</label>
            <input type="text" name="sending_ip" placeholder="203.0.113.1" class="w-full border rounded-lg px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">DKIM Selector (optional)</label>
            <input type="text" name="dkim_selector" placeholder="default" class="w-full border rounded-lg px-3 py-2">
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="run_sync" value="1"> Run immediately (sync)
        </label>
        <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg">Run Test</button>
    </form>

    <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold mb-4">Phase 2: Send Test Email</h3>
        <p class="text-sm text-slate-600 mb-4">Create a unique test inbox address. Send your campaign email to it, then IMAP will analyze headers (requires inbound mail setup).</p>
        <form action="{{ request()->is('admin*') ? route('admin.deliverability.inbox') : route('portal.deliverability.inbox') }}" method="POST">
            @csrf
            <button type="submit" class="bg-slate-700 text-white px-4 py-2 rounded-lg">Generate Test Inbox</button>
        </form>
        @if($inboxes->count())
            <div class="mt-4 space-y-2">
                @foreach($inboxes as $inbox)
                    <div class="text-xs font-mono bg-slate-50 p-2 rounded">{{ $inbox->email_address }} — {{ $inbox->status }}</div>
                @endforeach
            </div>
        @endif
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border overflow-hidden">
    <h3 class="font-semibold p-4 border-b">Recent Tests</h3>
    <table class="w-full text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="text-left p-3">Domain</th>
                <th class="text-left p-3">Score</th>
                <th class="text-left p-3">Status</th>
                <th class="text-left p-3">Date</th>
            </tr>
        </thead>
        <tbody>
            @forelse($tests as $test)
                <tr class="border-t hover:bg-slate-50">
                    <td class="p-3"><a href="{{ request()->is('admin*') ? route('admin.deliverability.show', $test) : route('portal.deliverability.show', $test) }}" class="text-indigo-600">{{ $test->domain }}</a></td>
                    <td class="p-3 font-bold">{{ $test->overall_score }}/10</td>
                    <td class="p-3">{{ $test->status }}</td>
                    <td class="p-3 text-slate-500">{{ $test->created_at->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-8 text-center text-slate-500">No tests yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<x-pagination :paginator="$tests" class="mt-4" />
@endsection
