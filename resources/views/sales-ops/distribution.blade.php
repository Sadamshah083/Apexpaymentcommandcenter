@extends('layouts.admin')

@section('title', 'Lead Distribution')

@section('content')
<div class="app-page space-y-6">
    <div>
        <h1 class="app-page-title">Lead Distribution Strategy</h1>
        <p class="app-page-subtitle">Each SDR holds up to {{ config('sales_ops.leads_per_sdr', 500) }} active leads. New leads assign only when capacity is available — maximizing pool penetration.</p>
    </div>

    <div class="app-card app-card-padded">
        <x-data-table :paginator="null" min-width="600px">
            <table>
                <thead>
                    <tr>
                        <th>SDR</th>
                        <th>Assigned Active Leads</th>
                        <th>Capacity</th>
                        <th>Available Slots</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="workspace-sync-sdr-load-body">
                    @foreach($sdrLoad as $row)
                        <tr>
                            <td class="font-bold">{{ $row['name'] }}</td>
                            <td>{{ $row['assigned'] }}</td>
                            <td>{{ $row['cap'] }}</td>
                            <td>{{ $row['available'] }}</td>
                            <td>
                                @if($row['at_capacity'])
                                    <span class="text-xs font-bold text-rose-600 bg-rose-50 px-2 py-1 rounded">At capacity — work existing pool</span>
                                @else
                                    <span class="text-xs font-bold text-emerald-600 bg-emerald-50 px-2 py-1 rounded">Accepting new leads</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-data-table>
    </div>
</div>
@endsection
