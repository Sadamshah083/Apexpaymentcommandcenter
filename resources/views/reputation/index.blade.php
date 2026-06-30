@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Reputation Center')

@section('content')
@php
    $routePrefix = request()->is('admin*') ? 'admin.' : 'portal.';
    $statusIcon = fn (string $status) => match ($status) {
        'pass' => ['&#10003;', 'text-green-600'],
        'warn' => ['!', 'text-amber-600'],
        'fail' => ['&#10007;', 'text-red-600'],
        default => ['i', 'text-blue-600'],
    };
@endphp
<div class="mb-8">
    <h2 class="text-2xl font-bold">Reputation Center</h2>
    <p class="text-slate-600">Google sender guidelines, warmup calculator, and list hygiene metrics.</p>
</div>

<div class="grid md:grid-cols-3 gap-4 mb-8">
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <p class="text-sm text-slate-500">Avg Invalid Rate</p>
        <p class="text-2xl font-bold {{ $hygiene['avg_invalid_rate'] > 5 ? 'text-red-600' : 'text-green-600' }}">{{ $hygiene['avg_invalid_rate'] }}%</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <p class="text-sm text-slate-500">Lists Needing Cleanup</p>
        <p class="text-2xl font-bold">{{ $hygiene['lists_needing_cleanup'] }}</p>
        <p class="text-xs text-slate-500">&gt;5% invalid/risky</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border p-5">
        <p class="text-sm text-slate-500">Total Lists</p>
        <p class="text-2xl font-bold">{{ $hygiene['total_lists'] }}</p>
    </div>
</div>

<div class="grid md:grid-cols-2 gap-6 mb-8">
    <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold mb-4">Google Compliance Checklist</h3>
        <form id="compliance-form" class="flex gap-2 mb-4">
            <input type="text" name="domain" id="compliance-domain" placeholder="yourdomain.com" value="{{ $complianceDomain }}" required class="flex-1 border rounded px-3 py-2 text-sm">
            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm">Check DNS</button>
        </form>
        <ul id="compliance-list" class="space-y-2 text-sm">
            @forelse($complianceChecklist as $item)
                @php [$icon, $color] = $statusIcon($item['status']); @endphp
                <li class="flex items-start gap-2">
                    <span class="{{ $color }}">{!! $icon !!}</span>
                    <span>
                        <span class="font-medium">{{ $item['label'] }}</span>
                        @if(!empty($item['detail']))
                            <span class="block text-xs text-slate-500">{{ $item['detail'] }}</span>
                        @endif
                    </span>
                </li>
            @empty
                <li class="text-slate-500">Enter a sending domain above to run live SPF, DKIM, and DMARC checks.</li>
            @endforelse
        </ul>
        <p class="text-xs text-slate-500 mt-4"><a href="https://postmaster.google.com/" target="_blank" class="text-indigo-600">Google Postmaster Tools</a> · <a href="https://support.google.com/mail/answer/81126" target="_blank" class="text-indigo-600">Sender Guidelines</a></p>
    </div>

    <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold mb-4">Log Postmaster Metrics</h3>
        <form action="{{ route($routePrefix.'reputation.log') }}" method="POST" class="space-y-3">
            @csrf
            <input type="text" name="domain" placeholder="yourdomain.com" required class="w-full border rounded px-3 py-2 text-sm">
            <select name="metric" class="w-full border rounded px-3 py-2 text-sm">
                <option value="spam_rate">Spam Rate</option>
                <option value="domain_reputation">Domain Reputation</option>
                <option value="ip_reputation">IP Reputation</option>
                <option value="delivery_errors">Delivery Errors</option>
            </select>
            <input type="text" name="value" placeholder="e.g. 0.05% or HIGH" class="w-full border rounded px-3 py-2 text-sm">
            <input type="date" name="recorded_at" value="{{ date('Y-m-d') }}" required class="w-full border rounded px-3 py-2 text-sm">
            <textarea name="notes" placeholder="Notes" rows="2" class="w-full border rounded px-3 py-2 text-sm"></textarea>
            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm">Save Log</button>
        </form>
    </div>
</div>

<div class="app-data-table mb-8">
    <div class="app-data-table-header flex flex-wrap items-end justify-between gap-4">
        <h3 class="app-data-table-title">Warmup Schedule</h3>
        <form id="warmup-form" class="flex flex-wrap gap-2 items-end text-sm">
            <label class="flex flex-col gap-1">
                <span class="text-slate-500">Target daily volume</span>
                <input type="number" name="target_daily" value="{{ $warmupTarget }}" min="100" max="100000" class="border rounded px-2 py-1 w-28">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-slate-500">Weeks</span>
                <input type="number" name="weeks" value="{{ $warmupWeeks }}" min="4" max="12" class="border rounded px-2 py-1 w-20">
            </label>
            <button type="submit" class="bg-slate-700 text-white px-3 py-1.5 rounded-lg">Recalculate</button>
        </form>
    </div>
    <div class="app-table-wrap">
        <table>
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
                @foreach(array_slice($warmupSchedule, 0, 14) as $row)
                    <tr>
                        <td>{{ $row['day'] }}</td>
                        <td>{{ $row['week'] }}</td>
                        <td class="font-mono">{{ number_format($row['daily_volume']) }}</td>
                        <td class="text-xs">{{ $row['focus'] }}</td>
                        <td class="text-xs">{{ $row['check'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="app-data-table-footer">
        <p id="warmup-summary" class="app-pagination-summary">Showing first 2 weeks of {{ count($warmupSchedule) }} days ramping to {{ number_format($warmupTarget) }}/day.</p>
    </div>
</div>

<x-data-table title="Reputation Logs" :paginator="$logs">
    <table>
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
                    <td>{{ $log->recorded_at->format('Y-m-d') }}</td>
                    <td>{{ $log->domain }}</td>
                    <td>{{ $log->metric }}</td>
                    <td>{{ $log->value }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center py-8 text-slate-500">No logs yet. Paste weekly data from Google Postmaster Tools.</td></tr>
            @endforelse
        </tbody>
    </table>
</x-data-table>
@endsection

@push('scripts')
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const complianceUrl = @json(route($routePrefix.'reputation.compliance'));
    const warmupUrl = @json(route($routePrefix.'reputation.warmup'));
    const statusIcon = @json([
        'pass' => ['&#10003;', 'text-green-600'],
        'warn' => ['!', 'text-amber-600'],
        'fail' => ['&#10007;', 'text-red-600'],
        'info' => ['i', 'text-blue-600'],
    ]);

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
            body: JSON.stringify({ domain }),
        });
        const data = await response.json();
        const list = document.getElementById('compliance-list');
        if (!list || !data.checklist) return;

        list.innerHTML = data.checklist.map((item) => {
            const [icon, color] = statusIcon[item.status] || statusIcon.info;
            const detail = item.detail ? `<span class="block text-xs text-slate-500">${item.detail}</span>` : '';
            return `<li class="flex items-start gap-2"><span class="${color}">${icon}</span><span><span class="font-medium">${item.label}</span>${detail}</span></li>`;
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
            body: JSON.stringify({ target_daily: targetDaily, weeks }),
        });
        const data = await response.json();
        const body = document.getElementById('warmup-body');
        const summary = document.getElementById('warmup-summary');
        if (!body || !data.schedule) return;

        body.innerHTML = data.schedule.slice(0, 14).map((row) => `
            <tr>
                <td>${row.day}</td>
                <td>${row.week}</td>
                <td class="font-mono">${Number(row.daily_volume).toLocaleString()}</td>
                <td class="text-xs">${row.focus}</td>
                <td class="text-xs">${row.check}</td>
            </tr>
        `).join('');

        if (summary) {
            summary.textContent = `Showing first 2 weeks of ${data.schedule.length} days ramping to ${targetDaily.toLocaleString()}/day.`;
        }
    });
})();
</script>
@endpush
