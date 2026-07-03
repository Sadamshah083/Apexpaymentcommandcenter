@extends('layouts.admin')

@section('title', 'Merchant Research')

@section('content')
    <div class="app-page business-research-page space-y-5">
        <div class="app-page-header flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
            <div>
                <h1 class="app-page-title">Merchant Research</h1>
                <p class="app-page-subtitle">Look up a single business with AI web research. For bulk lists, use Import
                    Leads.</p>
            </div>
            <a href="{{ route('admin.workflows.create') }}" class="app-btn app-btn-secondary shrink-0">Import leads</a>
        </div>

        <div class="business-research-panels grid gap-4 lg:grid-cols-2">
            <div class="app-card app-card-padded business-research-panel">
                <h2 class="app-section-title">Research a business</h2>
                <form action="{{ route('admin.business-research.store') }}" method="POST" class="business-research-form">
                    @csrf
                    <div class="app-field">
                        <label class="app-label" for="business_name">Business name <span
                                class="business-research-required">*</span></label>
                        <input type="text" name="business_name" id="business_name" value="{{ old('business_name') }}"
                            required placeholder="e.g. bluefrog Plumbing and Drain" class="app-input">
                    </div>
                    <div class="app-field">
                        <label class="app-label" for="address">Address / city, state</label>
                        <textarea name="address" id="address" rows="2" placeholder="Full address or city/state"
                            class="app-input">{{ old('address') }}</textarea>
                        <p class="business-research-field-hint">Helps match the correct location in search results.</p>
                    </div>
                    <div class="app-field">
                        <label class="app-label" for="website">Website (optional)</label>
                        <input type="text" name="website" id="website" value="{{ old('website') }}"
                            placeholder="https://example.com" class="app-input">
                    </div>
                    <button type="submit" class="app-btn app-btn-primary">Search and analyze</button>
                </form>
            </div>

            <div class="app-card app-card-padded business-research-panel business-research-findings">
                <h2 class="app-section-title">What we find</h2>
                <ul class="business-research-findings-list">
                    <li>Owner / decision-maker name</li>
                    <li>Payment processor (Square, Clover, etc.)</li>
                    <li>POS &amp; booking software</li>
                    <li>Public email and phone</li>
                    <li>Source links for verification</li>
                </ul>
            </div>
        </div>

        <x-data-table title="Recent research" :paginator="$researches" min-width="760px"
            class="business-research-data-table">
            <table class="business-research-table">
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
                        @php
                            $statusClass = match ($item->status) {
                                'completed' => 'app-badge app-badge-success',
                                'failed' => 'app-badge app-badge-danger',
                                'processing', 'pending' => 'app-badge app-badge-warning',
                                default => 'app-badge app-badge-muted',
                            };
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('admin.business-research.show', $item) }}"
                                    class="business-research-link">
                                    {{ Str::limit($item->business_name, 40) }}
                                </a>
                            </td>
                            <td class="business-research-cell">{{ $item->owner_name ?? '—' }}</td>
                            <td class="business-research-cell">{{ Str::limit($item->payment_processor ?? '—', 30) }}</td>
                            <td class="business-research-cell">{{ $item->confidence ?? '—' }}</td>
                            <td>
                                <span class="{{ $statusClass }}">{{ $item->status }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr class="business-research-empty-row">
                            <td colspan="5">
                                <div class="business-research-empty">
                                    <p class="business-research-empty-title">No research yet.</p>
                                    <p class="business-research-empty-desc">Search for a business above to get started.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-data-table>
    </div>
@endsection
