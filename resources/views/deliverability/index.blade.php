@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Deliverability')

@section('content')
    @php $routePrefix = request()->is('admin*') ? 'admin.' : 'portal.'; @endphp
    <div class="mb-8">
        <h2 class="text-2xl font-bold">Deliverability Checker</h2>
        <p class="text-slate-600">Test SPF, DKIM, DMARC, MX, PTR, and DNS blocklists — like mail-tester.com DNS checks.</p>
    </div>

    <div class="grid md:grid-cols-2 gap-6 mb-8">
        <form action="{{ route($routePrefix . 'deliverability.store') }}" method="POST"
            class="bg-white rounded-xl shadow-sm border p-6 space-y-4">
            @csrf
            <h3 class="font-semibold">Domain Authentication Test</h3>
            <div>
                <label class="block text-sm font-medium mb-1">Sending Domain</label>
                <input type="text" name="domain" placeholder="yourdomain.com" required
                    class="w-full border rounded-lg px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Sending IP (optional)</label>
                <input type="text" name="sending_ip" placeholder="203.0.113.1"
                    class="w-full border rounded-lg px-3 py-2">
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
            <h3 class="font-semibold mb-4">Send Test Email (Inbound Analysis)</h3>
            <p class="text-sm text-slate-600 mb-4">Create a unique test address, send your campaign email to it, and IMAP
                polling will analyze authentication headers and spam content.</p>
            @if (!$inboundDomainConfigured)
                <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-3 mb-4">
                    Configure <code>EMAIL_CHECKER_INBOUND_DOMAIN</code> to generate test addresses.
                </p>
            @elseif(!$inboundImapConfigured)
                <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-3 mb-4">
                    Set <code>EMAIL_CHECKER_IMAP_*</code> variables so inbound emails are analyzed automatically (polled
                    every 5 minutes).
                </p>
            @endif
            <form action="{{ route($routePrefix . 'deliverability.inbox') }}" method="POST">
                @csrf
                <button type="submit" class="bg-slate-700 text-white px-4 py-2 rounded-lg"
                    @disabled(!$inboundDomainConfigured)>Generate Test Inbox</button>
            </form>
            @if ($inboxes->count())
                <div class="mt-4 space-y-2" id="inbox-list">
                    @foreach ($inboxes as $inbox)
                        <div class="text-xs font-mono bg-slate-50 p-2 rounded flex justify-between gap-2 inbox-row"
                            data-inbox-id="{{ $inbox->id }}"
                            data-status-url="{{ route($routePrefix . 'deliverability.inbox.status', $inbox) }}">
                            <span>{{ $inbox->email_address }}</span>
                            <span class="inbox-status">{{ $inbox->status }}@if ($inbox->overall_score)
                                    — {{ $inbox->overall_score }}/10
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <x-data-table title="Recent Tests" :paginator="$tests">
        <table>
            <thead>
                <tr>
                    <th>Domain</th>
                    <th>Score</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                @forelse($tests as $test)
                    <tr>
                        <td><a href="{{ route($routePrefix . 'deliverability.show', $test) }}"
                                class="text-indigo-600">{{ $test->domain }}</a></td>
                        <td class="font-bold">{{ $test->overall_score }}/10</td>
                        <td>{{ $test->status }}</td>
                        <td class="text-slate-500">{{ $test->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center py-8 text-slate-500">No tests yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-data-table>
@endsection

@push('scripts')
    <script>
        (function() {
            const waiting = document.querySelectorAll('.inbox-row .inbox-status');
            if (!waiting.length) return;

            const poll = () => {
                document.querySelectorAll('.inbox-row').forEach(async (row) => {
                    const statusEl = row.querySelector('.inbox-status');
                    if (!statusEl || statusEl.textContent.startsWith('analyzed') || statusEl.textContent
                        .startsWith('expired')) {
                        return;
                    }

                    try {
                        const response = await fetch(row.dataset.statusUrl, {
                            headers: {
                                Accept: 'application/json'
                            },
                            credentials: 'same-origin'
                        });
                        const data = await response.json();
                        if (!data.status) return;
                        statusEl.textContent = data.overall_score ?
                            `${data.status} — ${data.overall_score}/10` :
                            data.status;
                    } catch {}
                });
            };

            poll();
            setInterval(poll, 15000);
        })();
    </script>
@endpush
