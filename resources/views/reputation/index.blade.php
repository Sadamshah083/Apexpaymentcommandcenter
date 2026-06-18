@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Reputation Center')

@section('content')
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
        <ul class="space-y-2 text-sm">
            <li class="flex items-start gap-2"><span class="text-green-600">&#10003;</span> SPF record configured on sending domain</li>
            <li class="flex items-start gap-2"><span class="text-green-600">&#10003;</span> DKIM signing enabled</li>
            <li class="flex items-start gap-2"><span class="text-green-600">&#10003;</span> DMARC record at _dmarc.domain (p=none minimum)</li>
            <li class="flex items-start gap-2"><span class="text-amber-600">!</span> One-click List-Unsubscribe header in emails</li>
            <li class="flex items-start gap-2"><span class="text-amber-600">!</span> Spam rate below 0.1% (monitor in Postmaster Tools)</li>
            <li class="flex items-start gap-2"><span class="text-amber-600">!</span> Never exceed 0.3% spam rate (Google blocks mitigation)</li>
            <li class="flex items-start gap-2"><span class="text-blue-600">i</span> Warm new domains 4-6 weeks before high volume</li>
        </ul>
        <p class="text-xs text-slate-500 mt-4"><a href="https://postmaster.google.com/" target="_blank" class="text-indigo-600">Google Postmaster Tools</a> · <a href="https://support.google.com/mail/answer/81126" target="_blank" class="text-indigo-600">Sender Guidelines</a></p>
    </div>

    <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold mb-4">Log Postmaster Metrics</h3>
        <form action="{{ request()->is('admin*') ? route('admin.reputation.log') : route('portal.reputation.log') }}" method="POST" class="space-y-3">
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

<div class="bg-white rounded-xl shadow-sm border p-6 mb-8">
    <h3 class="font-semibold mb-4">6-Week Warmup Schedule (to 50,000/day)</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="text-left p-2">Day</th>
                    <th class="text-left p-2">Week</th>
                    <th class="text-left p-2">Daily Volume</th>
                    <th class="text-left p-2">Focus</th>
                    <th class="text-left p-2">Check</th>
                </tr>
            </thead>
            <tbody>
                @foreach(array_slice($warmupSchedule, 0, 14) as $row)
                    <tr class="border-t">
                        <td class="p-2">{{ $row['day'] }}</td>
                        <td class="p-2">{{ $row['week'] }}</td>
                        <td class="p-2 font-mono">{{ number_format($row['daily_volume']) }}</td>
                        <td class="p-2 text-xs">{{ $row['focus'] }}</td>
                        <td class="p-2 text-xs">{{ $row['check'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="text-xs text-slate-500 mt-2">Showing first 2 weeks. Full schedule: 42 days ramping to 50k/day.</p>
</div>

<div class="bg-white rounded-xl shadow-sm border overflow-hidden">
    <h3 class="font-semibold p-4 border-b">Reputation Logs</h3>
    <table class="w-full text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="text-left p-3">Date</th>
                <th class="text-left p-3">Domain</th>
                <th class="text-left p-3">Metric</th>
                <th class="text-left p-3">Value</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr class="border-t">
                    <td class="p-3">{{ $log->recorded_at->format('Y-m-d') }}</td>
                    <td class="p-3">{{ $log->domain }}</td>
                    <td class="p-3">{{ $log->metric }}</td>
                    <td class="p-3">{{ $log->value }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-8 text-center text-slate-500">No logs yet. Paste weekly data from Google Postmaster Tools.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<x-pagination :paginator="$logs" class="mt-4" />
@endsection
