@extends('layouts.portal')

@section('title', 'Closer Assignment Queue')

@section('content')
    <div class="app-page space-y-6">
        <div>
            <h1 class="app-page-title">Closer Assignment Queue</h1>
            <p class="app-page-subtitle">Appointment-settled leads waiting for closer assignment.</p>
        </div>

        @include('pipeline.partials.portal-sync-context', ['portalView' => 'handoff_queue', 'leads' => $leads])

        <div id="portal-handoff-queue-empty" class="app-empty-state {{ $leads->isEmpty() ? '' : 'hidden' }}">
            <p class="app-empty-state-title">Queue is empty</p>
            <p class="app-empty-state-desc">Leads appear here when setters mark an appointment as settled.</p>
        </div>

        <div id="portal-handoff-queue-table" class="{{ $leads->isEmpty() ? 'hidden' : '' }}">
            <x-data-table :paginator="$leads" min-width="720px">
                <table>
                    <thead>
                        <tr>
                            <th>Business</th>
                            <th>Setter</th>
                            <th>Setter notes</th>
                            <th class="text-right">Assign</th>
                        </tr>
                    </thead>
                    <tbody id="workspace-sync-handoff-queue-body"
                        data-closers='@json($closers->map(fn ($closer) => ['id' => $closer->id, 'name' => $closer->name])->values())'>
                        @foreach ($leads as $lead)
                            <tr data-lead-id="{{ $lead->id }}">
                                <td data-col="business">
                                    <a href="{{ route('portal.leads.show', $lead->id) }}"
                                        class="font-bold text-zinc-900 hover:underline">{{ $lead->business_name }}</a>
                                    <div class="text-xs text-zinc-400">
                                        {{ $lead->city }}{{ $lead->state ? ', ' . $lead->state : '' }}</div>
                                </td>
                                <td class="text-sm" data-col="setter">{{ $lead->setter?->name ?? '—' }}</td>
                                <td class="text-sm text-zinc-600 max-w-md align-top" data-col="notes">
                                    @if (filled($lead->handoff_notes))
                                        <p class="whitespace-pre-wrap line-clamp-4">{{ $lead->handoff_notes }}</p>
                                        <a href="{{ route('portal.leads.show', $lead->id) }}"
                                            class="text-xs text-indigo-600 font-medium mt-1 inline-block">View full
                                            history</a>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <form method="POST" action="{{ route('portal.leads.assign-closer', $lead->id) }}"
                                        class="flex items-center justify-end gap-2">
                                        @csrf
                                        <select name="closer_id" required class="app-input app-input-sm">
                                            <option value="">Select closer…</option>
                                            @foreach ($closers as $closer)
                                                <option value="{{ $closer->id }}">{{ $closer->name }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="app-btn app-btn-primary app-btn-sm">Assign</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-data-table>
        </div>
    </div>
@endsection
