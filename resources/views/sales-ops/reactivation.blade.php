@extends('layouts.admin')

@section('title', 'Lead Reactivation')

@section('content')
<div class="app-page space-y-6">
    <div>
        <h1 class="app-page-title">Lead Reactivation Program</h1>
        <p class="app-page-subtitle">Revisit old leads, no-shows, lost opportunities, and expired proposals — historically higher conversion than cold outreach.</p>
    </div>

    <div class="app-card app-card-padded flex items-center justify-between">
        <div>
            <p class="app-kpi-label">Candidates in queue</p>
            <p id="workspace-sync-kpi-reactivation" class="app-kpi-value">{{ $candidates->count() }}</p>
        </div>
    </div>

    <div class="app-card app-card-padded">
        @if($candidates->isEmpty())
            <p class="text-sm text-zinc-500 italic">No reactivation candidates right now.</p>
        @else
            <x-data-table :paginator="null" min-width="900px">
                <table>
                    <thead>
                        <tr>
                            <th>Business</th>
                            <th>Stage</th>
                            <th>Last Updated</th>
                            <th>Enroll</th>
                        </tr>
                    </thead>
                <tbody id="workspace-sync-reactivation-body">
                        @foreach($candidates as $lead)
                            <tr data-lead-id="{{ $lead->id }}">
                                <td class="font-bold">{{ $lead->business_name }}</td>
                                <td>{{ \App\Support\SalesOps::crmStageLabel($lead->stage) }}</td>
                                <td class="text-xs text-zinc-500">{{ $lead->updated_at->diffForHumans() }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.sales-ops.reactivate', $lead->id) }}" class="flex items-center gap-2">
                                        @csrf
                                        <select name="source" class="app-input !w-auto text-xs py-1">
                                            @foreach($sources as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="app-btn app-btn-primary app-btn-sm">Enroll</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-data-table>
        @endif
    </div>
</div>
@endsection
