@extends('layouts.admin')

@section('title', $selectedTags->isNotEmpty() ? $selectedTags->pluck('name')->join(', ') : 'Tag leads')

@section('content')
    @php
        $filterParams = array_filter(
            [
                'tag_ids' => $selectedTagIds,
                'match' => $match,
                'list_ids' => $listIds,
            ],
            fn($v) => $v !== null && $v !== [],
        );
    @endphp

    <div class="app-page space-y-6">
        <div>
            <x-back-link :href="route('admin.lead-tags.index')" label="All tags" />
            <h1 class="app-page-title mt-2">
                @if ($selectedTags->isNotEmpty())
                    {{ $selectedTags->pluck('name')->join(' · ') }}
                @else
                    All tagged leads
                @endif
            </h1>
            <p class="app-page-subtitle">
                @if ($selectedTagIds !== [])
                    Leads from every import matching your tag filter — enrich or assign in one batch.
                @else
                    Every lead that has at least one tag across all imports.
                @endif
            </p>
        </div>

        @if (isset($enrichmentStatus))
            @include('workflows.partials.enrichment-status', ['status' => $enrichmentStatus])
        @endif

        <form method="GET" action="{{ route('admin.lead-tags.show') }}" class="app-card app-card-padded space-y-4">
            <h2 class="app-section-title">Filter</h2>

            <div class="app-field">
                <span class="app-label">Tags</span>
                <div class="flex flex-wrap gap-2 mt-2">
                    @foreach ($tags as $tag)
                        <label class="app-member-chip">
                            <input type="checkbox" name="tag_ids[]" value="{{ $tag->id }}"
                                @checked(in_array($tag->id, $selectedTagIds, true))>
                            <span class="app-member-chip-name"
                                style="border-left: 3px solid {{ $tag->color }}">{{ $tag->name }}</span>
                            <span class="app-member-chip-role">{{ $tag->leads_count }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="app-field">
                    <label class="app-label">Match</label>
                    <select name="match" class="app-input">
                        <option value="any" @selected($match === 'any')>Any selected tag</option>
                        <option value="all" @selected($match === 'all')>All selected tags</option>
                    </select>
                </div>
                <div class="app-field">
                    <label class="app-label">Status</label>
                    <select name="status" class="app-input">
                        <option value="">All statuses</option>
                        <option value="imported" @selected($status === 'imported')>Imported</option>
                        <option value="enriched" @selected($status === 'enriched')>Enriched</option>
                        <option value="failed" @selected($status === 'failed')>Failed</option>
                        <option value="completed" @selected($status === 'completed')>Released</option>
                    </select>
                </div>
                @if ($lists->isNotEmpty())
                    <div class="app-field sm:col-span-2">
                        <span class="app-label">Lists (optional)</span>
                        <div class="flex flex-wrap gap-2 mt-2">
                            @foreach ($lists as $list)
                                <label class="app-member-chip">
                                    <input type="checkbox" name="list_ids[]" value="{{ $list->id }}"
                                        @checked(in_array($list->id, $listIds, true))>
                                    <span class="app-member-chip-name">{{ $list->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <button type="submit" class="app-btn app-btn-secondary app-btn-sm">Apply filter</button>
        </form>

        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
            @foreach ([
            'total' => ['Total', 'text-zinc-900'],
            'imported' => ['Imported', 'text-zinc-700'],
            'enriched' => ['Enriched', 'text-sky-700'],
            'ready_to_distribute' => ['Ready to assign', 'text-emerald-700'],
            'failed' => ['Failed', 'text-rose-600'],
        ] as $key => [$label, $class])
                <div class="app-card app-card-padded text-center">
                    <p class="text-xs text-zinc-400 font-semibold">{{ $label }}</p>
                    <p class="text-2xl font-bold {{ $class }}">{{ number_format($counts[$key] ?? 0) }}</p>
                </div>
            @endforeach
        </div>

        @if ($selectedTagIds !== [])
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                @if (($counts['imported'] ?? 0) > 0 || ($counts['failed'] ?? 0) > 0)
                    <div class="app-card app-card-padded space-y-3 border-indigo-200">
                        <h3 class="app-section-title">Batch enrich</h3>
                        <p class="app-section-desc">Queue AI enrichment for
                            {{ number_format(($counts['imported'] ?? 0) + ($counts['failed'] ?? 0)) }} imported/failed
                            leads across all matching files.</p>
                        @if ($enrichmentConfigured ?? false)
                            <form method="POST" action="{{ route('admin.lead-tags.enrich') }}">
                                @csrf
                                @foreach ($selectedTagIds as $id)
                                    <input type="hidden" name="tag_ids[]" value="{{ $id }}">
                                @endforeach
                                <input type="hidden" name="match" value="{{ $match }}">
                                @foreach ($listIds as $listId)
                                    <input type="hidden" name="list_ids[]" value="{{ $listId }}">
                                @endforeach
                                <button type="submit" class="app-btn app-btn-primary app-btn-sm">Enrich matching
                                    leads</button>
                            </form>
                        @else
                            <p class="text-xs text-rose-600">{{ $enrichmentConfigMessage }}</p>
                        @endif
                    </div>
                @endif

                @if (($counts['ready_to_distribute'] ?? 0) > 0)
                    <div class="app-card app-card-padded space-y-3 border-emerald-200 bg-emerald-50/40">
                        <h3 class="app-section-title text-emerald-900">Batch assign setters</h3>
                        <p class="app-section-desc">Distribute {{ number_format($counts['ready_to_distribute']) }} enriched
                            leads from any import.</p>
                        <form method="POST" action="{{ route('admin.lead-tags.distribute') }}" class="space-y-3">
                            @csrf
                            @foreach ($selectedTagIds as $id)
                                <input type="hidden" name="tag_ids[]" value="{{ $id }}">
                            @endforeach
                            <input type="hidden" name="match" value="{{ $match }}">
                            @foreach ($listIds as $listId)
                                <input type="hidden" name="list_ids[]" value="{{ $listId }}">
                            @endforeach
                            <div class="flex flex-wrap gap-2">
                                @foreach ($team as $member)
                                    <label class="app-member-chip">
                                        <input type="checkbox" name="distribution_users[]" value="{{ $member->id }}"
                                            checked>
                                        <span class="app-member-chip-name">{{ $member->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <button type="submit" class="app-btn app-btn-primary app-btn-sm">Distribute matching
                                leads</button>
                        </form>
                    </div>
                @endif
            </div>
        @endif

        <div class="app-card app-card-padded">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                <h2 class="app-section-title">Leads</h2>
                @if ($selectedTagIds !== [] && ($tags ?? collect())->isNotEmpty())
                    <details class="app-details">
                        <summary class="text-sm font-semibold">Apply another tag to filtered leads</summary>
                        <form method="POST" action="{{ route('admin.lead-tags.apply') }}" class="mt-3 space-y-3">
                            @csrf
                            @foreach ($selectedTagIds as $id)
                                <input type="hidden" name="tag_ids[]" value="{{ $id }}">
                            @endforeach
                            <input type="hidden" name="match" value="{{ $match }}">
                            @foreach ($listIds as $listId)
                                <input type="hidden" name="list_ids[]" value="{{ $listId }}">
                            @endforeach
                            <div class="flex flex-wrap gap-2">
                                @foreach ($tags as $tag)
                                    <label class="app-member-chip">
                                        <input type="checkbox" name="apply_tag_ids[]" value="{{ $tag->id }}">
                                        <span class="app-member-chip-name"
                                            style="border-left: 3px solid {{ $tag->color }}">{{ $tag->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <button type="submit" class="app-btn app-btn-secondary app-btn-sm">Apply tag to all filtered
                                leads</button>
                        </form>
                    </details>
                @endif
            </div>

            <x-data-table :paginator="$leads" min-width="720px">
                <table>
                    <thead>
                        <tr>
                            <th>Business</th>
                            <th>Import</th>
                            <th>Tags</th>
                            <th>Phone</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($leads as $lead)
                            <tr>
                                <td>
                                    <a href="{{ route('portal.leads.show', $lead->id) }}"
                                        class="font-bold text-zinc-900 hover:underline">{{ $lead->business_name }}</a>
                                    @if ($lead->city || $lead->state)
                                        <div class="text-xs text-zinc-400">
                                            {{ $lead->city }}{{ $lead->city && $lead->state ? ', ' : '' }}{{ $lead->state }}
                                        </div>
                                    @endif
                                </td>
                                <td class="text-sm">
                                    @if ($lead->workflow)
                                        <a href="{{ route('admin.workflows.show', $lead->workflow_id) }}"
                                            class="app-link">{{ $lead->workflow->name }}</a>
                                        @if ($lead->leadList)
                                            <div class="text-xs text-zinc-400">{{ $lead->leadList->name }}</div>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($lead->tags as $tag)
                                            <a href="{{ route('admin.lead-tags.show', ['tag_ids' => [$tag->id]]) }}"
                                                class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-zinc-100 text-zinc-600 hover:bg-indigo-50"
                                                style="border-left: 2px solid {{ $tag->color }}">{{ $tag->name }}</a>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="text-sm text-zinc-600">{{ $lead->input_phone ?: '—' }}</td>
                                <td><x-lead-pipeline-badge :status="$lead->status" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center py-10 text-zinc-500">
                                    @if ($selectedTagIds === [])
                                        Select one or more tags above to view leads.
                                    @else
                                        No leads match this filter.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-data-table>
        </div>
    </div>
@endsection
