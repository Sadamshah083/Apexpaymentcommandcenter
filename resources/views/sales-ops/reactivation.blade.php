@extends('layouts.admin')

@section('title', 'Lead Reactivation')

@section('content')
<div class="app-page space-y-6">
    <div>
        <h1 class="app-page-title">Lead Reactivation Program</h1>
        <p class="app-page-subtitle">Revisit old leads, no-shows, lost opportunities, and expired proposals — historically higher conversion than cold outreach.</p>
    </div>

    <div class="app-card app-card-padded">
        @if($candidates->isEmpty())
            <p class="text-sm text-slate-500 italic">No reactivation candidates right now.</p>
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
                    <tbody>
                        @foreach($candidates as $lead)
                            <tr>
                                <td class="font-bold">{{ $lead->business_name }}</td>
                                <td>{{ \App\Support\SalesOps::crmStageLabel($lead->stage) }}</td>
                                <td class="text-xs text-slate-500">{{ $lead->updated_at->diffForHumans() }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.sales-ops.reactivate', $lead->id) }}" class="flex items-center gap-2">
                                        @csrf
                                        <select name="source" class="text-xs border border-slate-200 rounded-lg px-2 py-1">
                                            @foreach($sources as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="text-xs font-bold bg-indigo-600 text-white px-2 py-1 rounded">Enroll</button>
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
