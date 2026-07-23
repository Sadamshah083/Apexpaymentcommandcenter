@php
    use App\Support\CampaignChipTone;

    $campaign = $campaign ?? null;
    $compact = $compact ?? false;
    $linked = $linked ?? true;
@endphp
@if ($campaign)
    @php
        $chipClass = CampaignChipTone::className($campaign->id ?? null, $campaign->name ?? null, (bool) $compact);
    @endphp
    @if ($linked)
        <a href="{{ route('admin.campaigns.show', $campaign) }}" class="{{ $chipClass }}">{{ $campaign->name }}</a>
    @else
        <span class="{{ $chipClass }}">{{ $campaign->name }}</span>
    @endif
@endif
