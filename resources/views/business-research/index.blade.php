@extends('layouts.admin')

@section('title', 'Business Intelligence')

@section('content')
<div class="mb-8">
    <h2 class="text-2xl font-bold">Business Intelligence</h2>
    <p class="text-slate-600">Google AI mode–style research: Gemini 2.5 Pro + live Google Search. For bulk CSV enrichment use <a href="{{ route('admin.workflows.create') }}" class="text-indigo-600 underline">AI Agent Pipelines</a>.</p>
</div>

<div class="grid lg:grid-cols-2 gap-8 mb-8">
    <form action="{{ route('admin.business-research.store') }}" method="POST" class="bg-white rounded-xl shadow-sm border p-6 space-y-4">
        @csrf
        <h3 class="font-semibold text-lg">Research a Business</h3>

        <div>
            <label class="block text-sm font-medium mb-1">Business Name *</label>
            <input type="text" name="business_name" value="{{ old('business_name') }}" required
                placeholder="bluefrog Plumbing and Drain of West Houston"
                class="w-full border rounded-lg px-3 py-2">
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Address / City, State</label>
            <textarea name="address" rows="2" placeholder="1065 Richmond Ave Suite 140, Houston, TX 77006"
                class="w-full border rounded-lg px-3 py-2">{{ old('address') }}</textarea>
            <p class="text-xs text-slate-500 mt-1">Full address or city/state — used to match the correct location in search.</p>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Website (optional)</label>
            <input type="text" name="website" value="{{ old('website') }}" placeholder="https://example.com"
                class="w-full border rounded-lg px-3 py-2">
        </div>

        <p class="text-xs text-slate-500">Runs immediately on this page (typically 2–4 min). Searches directories, social media, Google SERP, and official sites.</p>

        <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded-lg hover:bg-indigo-700">
            Search Web & Analyze
        </button>

        <p class="text-xs text-slate-500">Uses Google Gemini + live Google Search grounding. Requires queue worker unless sync is checked.</p>
    </form>

    <div class="bg-slate-900 text-white rounded-xl p-6">
        <h3 class="font-semibold mb-3">What we find (POS sales intel)</h3>
        <ul class="text-sm space-y-2 text-slate-300">
            <li>• Owner / franchise owner name</li>
            <li>• Payment processor (Square, Clover, ServiceTitan Payments, etc.)</li>
            <li>• POS & field service software (ServiceTitan, Housecall Pro…)</li>
            <li>• Public emails & phone numbers</li>
            <li>• Franchise vs independent, business type</li>
            <li>• Source links for verification</li>
        </ul>
        <p class="text-xs text-slate-500 mt-4">Similar to Google AI mode — searches the web, then structures results for your sales team.</p>
    </div>
</div>

<x-data-table title="Recent Research" :paginator="$researches" class="mt-0">
    <table>
        <thead>
            <tr>
                <th>Business</th>
                <th>Owner</th>
                <th>Processor</th>
                <th>Confidence</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($researches as $item)
                <tr>
                    <td>
                        <a href="{{ route('admin.business-research.show', $item) }}" class="text-indigo-600 font-medium">
                            {{ Str::limit($item->business_name, 40) }}
                        </a>
                    </td>
                    <td>{{ $item->owner_name ?? '—' }}</td>
                    <td>{{ Str::limit($item->payment_processor ?? '—', 30) }}</td>
                    <td>{{ $item->confidence ?? '—' }}</td>
                    <td>
                        <span class="px-2 py-0.5 rounded text-xs bg-slate-100">{{ $item->status }}</span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center py-8 text-slate-500">No research yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</x-data-table>
@endsection
