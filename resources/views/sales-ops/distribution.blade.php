@extends('layouts.admin')

@section('title', 'Lead Distribution')

@section('content')
    <div class="app-page space-y-6">
        <div>
            <h1 class="app-page-title">Lead Distribution Strategy</h1>
            <p class="app-page-subtitle">Each SDR holds up to {{ config('sales_ops.leads_per_sdr', 500) }} active leads. New
                leads assign only when capacity is available — maximizing pool penetration.</p>
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
                        @foreach ($sdrLoad as $row)
                            <tr data-sdr-id="{{ $row['user_id'] }}">
                                <td class="font-bold">{{ $row['name'] }}</td>
                                <td id="workspace-sync-sdr-assigned-{{ $row['user_id'] }}">{{ $row['assigned'] }}</td>
                                <td>{{ $row['cap'] }}</td>
                                <td id="workspace-sync-sdr-available-{{ $row['user_id'] }}">{{ $row['available'] }}</td>
                                <td id="workspace-sync-sdr-status-{{ $row['user_id'] }}">
                                    @if ($row['at_capacity'])
                                        <span class="app-badge app-badge-danger">At capacity</span>
                                    @else
                                        <span class="app-badge app-badge-success">Accepting leads</span>
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
