@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Reputation Center')

@section('content')
    @php
        $routePrefix = request()->is('admin*') ? 'admin.' : 'portal.';
        $jsStatusIcons = [
            'pass' => ['&#10003;', 'reputation-status reputation-status--pass'],
            'warn' => ['!', 'reputation-status reputation-status--warn'],
            'fail' => ['&#10007;', 'reputation-status reputation-status--fail'],
            'info' => ['i', 'reputation-status reputation-status--info'],
        ];
    @endphp

    <div class="app-page reputation-page space-y-5">
        <div class="app-page-header">
            <h1 class="app-page-title">Reputation Center</h1>
            <p class="app-page-subtitle">Google sender guidelines, warmup calculator, and list hygiene metrics.</p>
        </div>

        <div class="grid gap-3 md:grid-cols-3 app-stat-grid reputation-stats">
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Avg Invalid Rate</p>
                <p class="app-kpi-value {{ $hygiene['avg_invalid_rate'] > 5 ? 'reputation-kpi--bad' : 'reputation-kpi--good' }}">
                    {{ $hygiene['avg_invalid_rate'] }}%
                </p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Lists Needing Cleanup</p>
                <p class="app-kpi-value">{{ $hygiene['lists_needing_cleanup'] }}</p>
                <p class="reputation-stat-meta">&gt;5% invalid/risky</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Total Lists</p>
                <p class="app-kpi-value">{{ $hygiene['total_lists'] }}</p>
            </div>
        </div>

        <div class="reputation-panels grid gap-4 md:grid-cols-2">
            <div class="app-card app-card-padded reputation-panel">
                <h2 class="app-section-title">Google Compliance Checklist</h2>
                <form id="compliance-form" class="reputation-inline-form">
                    <input type="text" name="domain" id="compliance-domain" placeholder="yourdomain.com" required
                        class="app-input reputation-inline-input">
                    <button type="submit" class="app-btn app-btn-primary shrink-0">Check DNS</button>
                </form>
                <ul id="compliance-list" class="reputation-compliance-list">
                    <li class="reputation-compliance-placeholder">Enter a sending domain above to run live SPF, DKIM, and
                        DMARC checks.</li>
                </ul>
                <p class="reputation-links">
                    <a href="https://postmaster.google.com/" target="_blank" rel="noopener"
                        class="reputation-link">Google Postmaster Tools</a>
                    ·
                    <a href="https://support.google.com/mail/answer/81126" target="_blank" rel="noopener"
                        class="reputation-link">Sender Guidelines</a>
                </p>
            </div>

            <div class="app-card app-card-padded reputation-panel">
                <h2 class="app-section-title">Log Postmaster Metrics</h2>
                <form action="{{ route($routePrefix . 'reputation.log') }}" method="POST" class="reputation-log-form">
                    @csrf
                    <div class="app-field">
                        <label class="app-label" for="log-domain">Domain</label>
                        <input type="text" name="domain" id="log-domain" placeholder="yourdomain.com" required
                            class="app-input" value="{{ old('domain') }}">
                    </div>
                    <div class="app-field">
                        <label class="app-label" for="log-metric">Metric</label>
                        <select name="metric" id="log-metric" class="app-input">
                            <option value="spam_rate">Spam Rate</option>
                            <option value="domain_reputation">Domain Reputation</option>
                            <option value="ip_reputation">IP Reputation</option>
                            <option value="delivery_errors">Delivery Errors</option>
                        </select>
                    </div>
                    <div class="app-field">
                        <label class="app-label" for="log-value">Value</label>
                        <input type="text" name="value" id="log-value" placeholder="e.g. 0.05% or HIGH"
                            class="app-input" value="{{ old('value') }}">
                    </div>
                    <div class="app-field">
                        <label class="app-label" for="log-date">Date</label>
                        <input type="date" name="recorded_at" id="log-date" value="{{ old('recorded_at', date('Y-m-d')) }}"
                            required class="app-input">
                    </div>
                    <div class="app-field">
                        <label class="app-label" for="log-notes">Notes</label>
                        <textarea name="notes" id="log-notes" rows="2" placeholder="Notes"
                            class="app-input">{{ old('notes') }}</textarea>
                    </div>
                    <button type="submit" class="app-btn app-btn-primary">Save Log</button>
                </form>
            </div>
        </div>

        <div class="app-data-table reputation-warmup-table">
            <div class="app-data-table-header reputation-warmup-header">
                <h2 class="app-data-table-title">Warmup Schedule</h2>
                <form id="warmup-form" class="reputation-warmup-form">
                    <label class="app-field reputation-warmup-field">
                        <span class="app-label">Target daily volume</span>
                        <input type="number" name="target_daily" value="{{ $warmupTarget }}" min="100" max="100000"
                            class="app-input reputation-warmup-input">
                    </label>
                    <label class="app-field reputation-warmup-field">
                        <span class="app-label">Weeks</span>
                        <input type="number" name="weeks" value="{{ $warmupWeeks }}" min="4" max="12"
                            class="app-input reputation-warmup-input reputation-warmup-input--weeks">
                    </label>
                    <button type="submit" class="app-btn app-btn-secondary">Recalculate</button>
                </form>
            </div>
            <div class="app-table-wrap">
                <table class="reputation-table">
                    <thead>
                        <tr>
                            <th>Day</th>
                            <th>Week</th>
                            <th>Daily Volume</th>
                            <th>Focus</th>
                            <th>Check</th>
                        </tr>
                    </thead>
                    <tbody id="warmup-body">
                        @foreach (array_slice($warmupSchedule, 0, 14) as $row)
                            <tr>
                                <td>{{ $row['day'] }}</td>
                                <td>{{ $row['week'] }}</td>
                                <td class="reputation-volume">{{ number_format($row['daily_volume']) }}</td>
                                <td class="reputation-cell-muted">{{ $row['focus'] }}</td>
                                <td class="reputation-cell-muted">{{ $row['check'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="app-data-table-footer">
                <p id="warmup-summary" class="reputation-table-summary">Showing first 2 weeks of
                    {{ count($warmupSchedule) }} days ramping to {{ number_format($warmupTarget) }}/day.</p>
            </div>
        </div>

        <x-data-table title="Reputation Logs" :paginator="$logs" min-width="640px" class="reputation-logs-table">
            <table class="reputation-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Domain</th>
                        <th>Metric</th>
                        <th>Value</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td class="reputation-date">{{ $log->recorded_at->format('Y-m-d') }}</td>
                            <td class="reputation-domain">{{ $log->domain }}</td>
                            <td>{{ $log->metric }}</td>
                            <td class="reputation-value">{{ $log->value }}</td>
                        </tr>
                    @empty
                        <tr class="reputation-empty-row">
                            <td colspan="4">
                                <div class="reputation-empty">
                                    <p class="reputation-empty-title">No logs yet.</p>
                                    <p class="reputation-empty-desc">Paste weekly data from Google Postmaster Tools.</p>
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
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const complianceUrl = @json(route($routePrefix . 'reputation.compliance'));
            const warmupUrl = @json(route($routePrefix . 'reputation.warmup'));
            const statusIcon = @json($jsStatusIcons);

            const escapeHtml = (value) => String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');

            document.getElementById('compliance-form')?.addEventListener('submit', async (event) => {
                event.preventDefault();
                const domain = document.getElementById('compliance-domain')?.value;
                if (!domain) return;

                const response = await fetch(complianceUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        domain
                    }),
                });
                const data = await response.json();
                const list = document.getElementById('compliance-list');
                if (!list || !data.checklist) return;

                list.innerHTML = data.checklist.map((item) => {
                    const [icon, className] = statusIcon[item.status] || statusIcon.info;
                    const detail = item.detail ?
                        `<span class="reputation-compliance-detail">${escapeHtml(item.detail)}</span>` :
                        '';
                    return `<li class="reputation-compliance-item"><span class="${className}">${icon}</span><span><span class="reputation-compliance-label">${escapeHtml(item.label)}</span>${detail}</span></li>`;
                }).join('');
            });

            document.getElementById('warmup-form')?.addEventListener('submit', async (event) => {
                event.preventDefault();
                const form = event.target;
                const targetDaily = Number(form.target_daily.value);
                const weeks = Number(form.weeks.value);

                const response = await fetch(warmupUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        target_daily: targetDaily,
                        weeks
                    }),
                });
                const data = await response.json();
                const body = document.getElementById('warmup-body');
                const summary = document.getElementById('warmup-summary');
                if (!body || !data.schedule) return;

                body.innerHTML = data.schedule.slice(0, 14).map((row) => `
                    <tr>
                        <td>${row.day}</td>
                        <td>${row.week}</td>
                        <td class="reputation-volume">${Number(row.daily_volume).toLocaleString()}</td>
                        <td class="reputation-cell-muted">${escapeHtml(row.focus)}</td>
                        <td class="reputation-cell-muted">${escapeHtml(row.check)}</td>
                    </tr>
                `).join('');

                if (summary) {
                    summary.textContent =
                        `Showing first 2 weeks of ${data.schedule.length} days ramping to ${targetDaily.toLocaleString()}/day.`;
                }
            });
        })();
    </script>
@endpush
