@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Deliverability')

@section('content')
    @php $routePrefix = request()->is('admin*') ? 'admin.' : 'portal.'; @endphp

    <div class="app-page deliverability-page space-y-5">
        <div class="app-page-header">
            <h1 class="app-page-title">Deliverability Checker</h1>
            <p class="app-page-subtitle">Test SPF, DKIM, DMARC, MX, PTR, and DNS blocklists — like mail-tester.com DNS
                checks.</p>
        </div>

        <div class="deliverability-panels grid md:grid-cols-2 gap-4">
            <form action="{{ route($routePrefix . 'deliverability.store') }}" method="POST"
                class="app-card app-card-padded deliverability-panel space-y-4">
                @csrf
                <h2 class="app-section-title">Domain Authentication Test</h2>

                <div class="app-field">
                    <label class="app-label" for="domain">Sending Domain</label>
                    <input type="text" name="domain" id="domain" placeholder="yourdomain.com" required
                        class="app-input" value="{{ old('domain') }}">
                </div>

                <div class="app-field">
                    <label class="app-label" for="sending_ip">Sending IP (optional)</label>
                    <input type="text" name="sending_ip" id="sending_ip" placeholder="203.0.113.1"
                        class="app-input" value="{{ old('sending_ip') }}">
                </div>

                <div class="app-field">
                    <label class="app-label" for="dkim_selector">DKIM Selector (optional)</label>
                    <input type="text" name="dkim_selector" id="dkim_selector" placeholder="default"
                        class="app-input" value="{{ old('dkim_selector') }}">
                </div>

                <label class="deliverability-checkbox">
                    <input type="checkbox" name="run_sync" value="1" @checked(old('run_sync'))>
                    <span>Run immediately (sync)</span>
                </label>

                <button type="submit" class="app-btn app-btn-primary">Run Test</button>
            </form>

            <div class="app-card app-card-padded deliverability-panel space-y-4">
                <h2 class="app-section-title">Send Test Email (Inbound Analysis)</h2>
                <p class="deliverability-panel-desc">Create a unique test address, send your campaign email to it, and IMAP
                    polling will analyze authentication headers and spam content.</p>

                @if (!$inboundDomainConfigured)
                    <div class="deliverability-alert">
                        Configure <code>EMAIL_CHECKER_INBOUND_DOMAIN</code> to generate test addresses.
                    </div>
                @elseif(!$inboundImapConfigured)
                    <div class="deliverability-alert">
                        Set <code>EMAIL_CHECKER_IMAP_*</code> variables so inbound emails are analyzed automatically
                        (polled every 5 minutes).
                    </div>
                @endif

                <form action="{{ route($routePrefix . 'deliverability.inbox') }}" method="POST">
                    @csrf
                    <button type="submit" class="app-btn app-btn-primary"
                        @disabled(!$inboundDomainConfigured)>Generate Test Inbox</button>
                </form>

                @if ($inboxes->count())
                    <div class="deliverability-inbox-list" id="inbox-list">
                        @foreach ($inboxes as $inbox)
                            <div class="deliverability-inbox-row inbox-row" data-inbox-id="{{ $inbox->id }}"
                                data-status-url="{{ route($routePrefix . 'deliverability.inbox.status', $inbox) }}">
                                <span class="deliverability-inbox-email">{{ $inbox->email_address }}</span>
                                <span class="deliverability-inbox-status inbox-status">{{ $inbox->status }}@if ($inbox->overall_score)
                                        — {{ $inbox->overall_score }}/10
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <x-data-table title="Recent Tests" :paginator="$tests" min-width="720px" class="deliverability-data-table">
            <table class="deliverability-table">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Score</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody id="workspace-sync-deliverability-body">
                    @forelse($tests as $test)
                        <tr data-deliverability-id="{{ $test->id }}">
                            <td>
                                <a href="{{ route($routePrefix . 'deliverability.show', $test) }}"
                                    class="deliverability-domain-link">{{ $test->domain }}</a>
                            </td>
                            <td>
                                <span class="deliverability-score">{{ $test->overall_score ?? '—' }}@if ($test->overall_score !== null)
                                        /10
                                    @endif
                                </span>
                            </td>
                            <td>
                                @php
                                    $statusClass = match ($test->status) {
                                        'completed' => 'app-badge app-badge-success',
                                        'processing', 'pending' => 'app-badge app-badge-warning',
                                        'failed' => 'app-badge app-badge-danger',
                                        default => 'app-badge app-badge-muted',
                                    };
                                @endphp
                                <span class="{{ $statusClass }}">{{ $test->status }}</span>
                            </td>
                            <td class="deliverability-date">{{ $test->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr class="deliverability-empty-row">
                            <td colspan="4">
                                <div class="deliverability-empty">
                                    <p class="deliverability-empty-title">No tests yet.</p>
                                    <p class="deliverability-empty-desc">Run a domain authentication test above to get
                                        started.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-data-table>
    </div>
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
