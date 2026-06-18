@extends('layouts.admin')

@section('title', 'Business CRM')

@section('content')
<div class="mb-8 flex flex-wrap justify-between items-start gap-4">
    <div>
        <h2 class="text-2xl font-bold">Business CRM</h2>
        <p class="text-slate-600 mt-1">Upload CSV leads — AI enriches owner, phone, email, payment processor, and POS data.</p>
    </div>
    <a href="{{ route('crm.create') }}" class="bg-indigo-600 text-white px-5 py-2 rounded-lg hover:bg-indigo-700">
        Upload CSV
    </a>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
    <div class="bg-white rounded-xl p-5 shadow-sm border">
        <p class="text-sm text-slate-500">Campaigns</p>
        <p class="text-3xl font-bold text-indigo-600">{{ $stats['total_campaigns'] }}</p>
    </div>
    <div class="bg-white rounded-xl p-5 shadow-sm border">
        <p class="text-sm text-slate-500">Total Leads</p>
        <p class="text-3xl font-bold">{{ number_format($stats['total_leads']) }}</p>
    </div>
    <div class="bg-white rounded-xl p-5 shadow-sm border">
        <p class="text-sm text-slate-500">Enriched</p>
        <p class="text-3xl font-bold text-green-600">{{ number_format($stats['completed_leads']) }}</p>
    </div>
    <div class="bg-white rounded-xl p-5 shadow-sm border">
        <p class="text-sm text-slate-500">Processing</p>
        <p class="text-3xl font-bold text-amber-600">{{ $stats['processing_campaigns'] }}</p>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border overflow-hidden">
    <div class="p-5 border-b flex justify-between items-center">
        <h3 class="font-semibold">Campaigns</h3>
        <a href="{{ route('business-research.index') }}" class="text-sm text-indigo-600 hover:underline">Single business lookup</a>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left">
            <tr>
                <th class="p-3">Name</th>
                <th class="p-3">File</th>
                <th class="p-3">Leads</th>
                <th class="p-3">Progress</th>
                <th class="p-3">Status</th>
                <th class="p-3">Created</th>
            </tr>
        </thead>
        <tbody>
            @forelse($campaigns as $campaign)
                <tr class="border-t hover:bg-slate-50">
                    <td class="p-3">
                        <a href="{{ route('crm.show', $campaign) }}" class="font-medium text-indigo-600 hover:underline">
                            {{ $campaign->name }}
                        </a>
                    </td>
                    <td class="p-3 text-slate-500">{{ $campaign->original_filename ?? '—' }}</td>
                    <td class="p-3">{{ $campaign->total_leads }}</td>
                    <td class="p-3">
                        <div class="flex items-center gap-2">
                            <div class="w-24 bg-slate-200 rounded-full h-2">
                                <div class="bg-indigo-600 h-2 rounded-full" style="width: {{ $campaign->progressPercent() }}%"></div>
                            </div>
                            <span class="text-xs text-slate-500">{{ $campaign->progressPercent() }}%</span>
                        </div>
                    </td>
                    <td class="p-3">
                        @php
                            $badge = match($campaign->status) {
                                'completed' => 'bg-green-100 text-green-800',
                                'processing', 'importing' => 'bg-amber-100 text-amber-800',
                                'failed' => 'bg-red-100 text-red-800',
                                default => 'bg-slate-100 text-slate-700',
                            };
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs {{ $badge }}">{{ $campaign->status }}</span>
                    </td>
                    <td class="p-3 text-slate-500">{{ $campaign->created_at->diffForHumans() }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="p-8 text-center text-slate-500">
                        No campaigns yet. <a href="{{ route('crm.create') }}" class="text-indigo-600">Upload a CSV</a> to start.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    @if($campaigns->hasPages())
        <x-pagination :paginator="$campaigns" class="p-4 border-t" />
    @endif
</div>
@endsection
