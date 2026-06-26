@extends('layouts.portal')

@section('title', 'AE Pipeline')

@section('content')
<div class="app-page space-y-6">
    <div>
        <h1 class="app-page-title">Account Executive Pipeline</h1>
        <p class="app-page-subtitle">Meetings scheduled, proposals sent, and deals in follow-up — qualified opportunities only.</p>
    </div>

    <x-data-table :paginator="$leads" min-width="800px">
        <table>
            <thead>
                <tr>
                    <th>Business</th>
                    <th>Owner</th>
                    <th>Stage</th>
                    <th>Volume</th>
                    <th>Processor</th>
                    <th>Meeting</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($leads as $lead)
                    <tr>
                        <td class="font-bold">{{ $lead->business_name }}</td>
                        <td>{{ $lead->owner_name ?: '—' }}</td>
                        <td>{{ \App\Support\SalesOps::crmStageLabel($lead->stage) }}</td>
                        <td>{{ $lead->monthly_processing_volume ? '$'.number_format($lead->monthly_processing_volume) : '—' }}</td>
                        <td>{{ $lead->current_processor ?: '—' }}</td>
                        <td class="text-xs">{{ $lead->schedule_at?->format('M j, g:i A') ?: '—' }}</td>
                        <td><a href="{{ route('portal.leads.show', $lead->id) }}" class="text-indigo-600 text-sm font-semibold">Open</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-data-table>
</div>
@endsection
