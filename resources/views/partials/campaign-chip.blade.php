@php
    $campaign = $campaign ?? null;
    $compact = $compact ?? false;
    $linked = $linked ?? true;
@endphp
@if ($campaign)
    @if ($linked)
        <a href="{{ route('admin.campaigns.show', $campaign) }}" class="campaign-chip {{ $compact ? 'campaign-chip--sm' : '' }}">{{ $campaign->name }}</a>
    @else
        <span class="campaign-chip {{ $compact ? 'campaign-chip--sm' : '' }}">{{ $campaign->name }}</span>
    @endif
@endif
