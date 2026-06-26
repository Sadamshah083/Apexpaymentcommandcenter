@extends('layouts.admin')

@section('title', 'Merchant Research')

@section('content')
<div class="app-page space-y-6">
    <x-page-header
        title="Merchant Research"
        subtitle="Look up a single business with AI web research. For bulk lists, use Import Leads."
    >
        <x-slot:actions>
            <a href="{{ route('admin.workflows.create') }}" class="app-btn app-btn-secondary app-btn-sm">Import leads</a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid lg:grid-cols-2 gap-6">
        <div class="app-card app-card-padded">
            <h2 class="app-section-title mb-4">Research a business</h2>
            <form action="{{ route('admin.business-research.store') }}" method="POST" class="space-y-4">
                @csrf
                <div class="app-field">
                    <label class="app-label">Business name <span class="text-rose-600">*</span></label>
                    <input type="text" name="business_name" value="{{ old('business_name') }}" required
                        placeholder="e.g. bluefrog Plumbing and Drain"
                        class="app-input">
                </div>
                <div class="app-field">
                    <label class="app-label">Address / city, state</label>
                    <textarea name="address" rows="2" placeholder="Full address or city/state" class="app-input">{{ old('address') }}</textarea>
                    <p class="text-xs text-zinc-400 mt-1">Helps match the correct location in search results.</p>
                </div>
                <div class="app-field">
                    <label class="app-label">Website (optional)</label>
                    <input type="text" name="website" value="{{ old('website') }}" placeholder="https://example.com" class="app-input">
                </div>
                <button type="submit" class="app-btn app-btn-primary">Search and analyze</button>
            </form>
        </div>

        <div class="app-card app-card-padded">
            <h2 class="app-section-title mb-3 text-zinc-900">What we find</h2>
            <ul class="text-sm space-y-2 text-zinc-900">
                <li class="flex gap-2"><span>•</span> Owner / decision-maker name</li>
                <li class="flex gap-2"><span>•</span> Payment processor (Square, Clover, etc.)</li>
                <li class="flex gap-2"><span>•</span> POS &amp; booking software</li>
                <li class="flex gap-2"><span>•</span> Public email and phone</li>
                <li class="flex gap-2"><span>•</span> Source links for verification</li>
            </ul>
        </div>
    </div>

    <x-data-table title="Recent research" :paginator="$researches">
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
                            <a href="{{ route('admin.business-research.show', $item) }}" class="font-semibold text-zinc-900 hover:underline">
                                {{ Str::limit($item->business_name, 40) }}
                            </a>
                        </td>
                        <td class="text-zinc-600">{{ $item->owner_name ?? '—' }}</td>
                        <td class="text-zinc-600">{{ Str::limit($item->payment_processor ?? '—', 30) }}</td>
                        <td class="text-zinc-600">{{ $item->confidence ?? '—' }}</td>
                        <td>
                            @php
                                $statusClass = match($item->status) {
                                    'completed' => 'app-badge-success',
                                    'failed' => 'app-badge-danger',
                                    'processing', 'pending' => 'app-badge-info',
                                    default => 'app-badge-muted',
                                };
                            @endphp
                            <span class="app-badge {{ $statusClass }}">{{ $item->status }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center py-8 text-zinc-400">No research yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-data-table>
</div>
@endsection
